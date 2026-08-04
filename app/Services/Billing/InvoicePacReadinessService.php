<?php

namespace App\Services\Billing;

use App\Enums\TaxType;
use App\Exceptions\Billing\InvoiceFiscalSnapshotIncompleteException;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Support\Collection;

/**
 * Centraliza el concepto "Invoice lista fiscalmente para el PAC" (Fase
 * 6.2.3) — antes disperso dentro de
 * `FacturapiProvider::assertSnapshotIsComplete()` (Fase 6.2.1/6.2.2).
 * Puramente de lectura: NO hace HTTP, no llama a ningún PacProvider, no
 * modifica la Invoice. Arquitectura preferida (ver reporte de entrega):
 *
 *   IssueInvoiceService → InvoicePacReadinessService → PacProvider
 *
 * FacturapiProvider ya NO valida nada de esto — se concentra únicamente
 * en traducir el snapshot ya validado al payload de Facturapi. Si algún
 * día se cambia de PAC, el nuevo adaptador reutiliza este mismo
 * servicio sin duplicar reglas.
 *
 * Códigos SAT c_Impuesto reconocidos (001 ISR, 002 IVA, 003 IEPS): deben
 * mantenerse en sincronía con `FacturapiProvider::TAX_CODE_NAMES` — ahí
 * vive la traducción al nombre que espera Facturapi (conocimiento
 * específico de ese PAC); aquí solo se valida que el código sea un
 * valor SAT real (conocimiento neutral de PAC).
 */
class InvoicePacReadinessService
{
    private const RECOGNIZED_SAT_TAX_CODES = ['001', '002', '003'];

    private const VALID_PAYMENT_METHODS = ['PUE', 'PPD'];

    /**
     * @return array{ready: bool, errors: array<int, array{code: string, field: string, message: string}>}
     */
    public function evaluate(Invoice $invoice): array
    {
        $errors = [];

        $invoice->loadMissing('items');

        $this->checkReceptor($invoice, $errors);
        $this->checkEmision($invoice, $errors);
        $this->checkItems($invoice->items, $errors);

        return [
            'ready' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * Conveniencia para IssueInvoiceService: evalúa y, si no está lista,
     * lanza InvoiceFiscalSnapshotIncompleteException (mismo tipo que ya
     * usaban y esperaban las pruebas de Fase 6.2.1/6.2.2 — no se cambia
     * su forma, solo de dónde se lanza).
     */
    public function assertReady(Invoice $invoice): void
    {
        $result = $this->evaluate($invoice);

        if (! $result['ready']) {
            $missing = array_map(
                fn (array $error): string => "{$error['field']} ({$error['code']}): {$error['message']}",
                $result['errors'],
            );

            throw new InvoiceFiscalSnapshotIncompleteException($invoice->id, $missing);
        }
    }

    /**
     * @param  array<int, array{code: string, field: string, message: string}>  $errors
     */
    private function checkReceptor(Invoice $invoice, array &$errors): void
    {
        if (blank($invoice->client_name)) {
            $errors[] = $this->error('INVOICE_CLIENT_NAME_MISSING', 'client_name', 'Falta client_name (customer.legal_name).');
        }
        if (blank($invoice->client_rfc)) {
            $errors[] = $this->error('INVOICE_CLIENT_RFC_MISSING', 'client_rfc', 'Falta client_rfc (customer.tax_id).');
        }
        if (blank($invoice->client_regimen_fiscal)) {
            $errors[] = $this->error('INVOICE_CLIENT_FISCAL_REGIME_MISSING', 'client_regimen_fiscal', 'Falta client_regimen_fiscal (customer.tax_system).');
        }
        if (blank($invoice->client_uso_cfdi)) {
            $errors[] = $this->error('INVOICE_CLIENT_CFDI_USE_MISSING', 'client_uso_cfdi', 'Falta client_uso_cfdi (use).');
        }
        if (blank($invoice->client_codigo_postal)) {
            $errors[] = $this->error('INVOICE_CLIENT_POSTAL_CODE_MISSING', 'client_codigo_postal', 'Falta client_codigo_postal (customer.address.zip).');
        }
    }

    /**
     * @param  array<int, array{code: string, field: string, message: string}>  $errors
     */
    private function checkEmision(Invoice $invoice, array &$errors): void
    {
        if (blank($invoice->payment_form)) {
            $errors[] = $this->error('INVOICE_PAYMENT_FORM_MISSING', 'payment_form', 'Falta payment_form (SAT c_FormaPago), requerido por Facturapi.');
        } elseif (! preg_match('/^\d{2}$/', (string) $invoice->payment_form)) {
            $errors[] = $this->error('INVOICE_PAYMENT_FORM_INVALID_FORMAT', 'payment_form', "payment_form tiene formato inválido ('{$invoice->payment_form}'); se esperan exactamente 2 dígitos numéricos.");
        }

        if ($invoice->payment_method !== null && ! in_array($invoice->payment_method, self::VALID_PAYMENT_METHODS, true)) {
            $errors[] = $this->error('INVOICE_PAYMENT_METHOD_INVALID', 'payment_method', "payment_method tiene un valor inválido ('{$invoice->payment_method}'); debe ser 'PUE' o 'PPD' cuando se envía.");
        }

        if (blank($invoice->currency) || ! preg_match('/^[A-Z]{3}$/', (string) $invoice->currency)) {
            $errors[] = $this->error('INVOICE_INVALID_CURRENCY', 'currency', 'La factura no tiene una moneda válida (código de 3 letras).');
        }

        if ($invoice->items->isEmpty()) {
            $errors[] = $this->error('INVOICE_NO_ITEMS', 'items', 'La factura no tiene líneas.');
        }
    }

    /**
     * @param  Collection<int, InvoiceItem>  $items
     * @param  array<int, array{code: string, field: string, message: string}>  $errors
     */
    private function checkItems(Collection $items, array &$errors): void
    {
        foreach ($items as $item) {
            $prefix = "items[{$item->id}]";

            if (blank($item->description)) {
                $errors[] = $this->error('INVOICE_ITEM_DESCRIPTION_MISSING', "{$prefix}.description", 'Falta description en la línea.');
            }
            if ((float) $item->quantity <= 0) {
                $errors[] = $this->error('INVOICE_ITEM_INVALID_QUANTITY', "{$prefix}.quantity", 'La línea tiene una cantidad inválida (debe ser mayor a 0).');
            }
            if ((float) $item->unit_price < 0) {
                $errors[] = $this->error('INVOICE_ITEM_INVALID_UNIT_PRICE', "{$prefix}.unit_price", 'La línea tiene un precio unitario inválido (no puede ser negativo).');
            }
            if (blank($item->product_clave_producto)) {
                $errors[] = $this->error('INVOICE_ITEM_PRODUCT_KEY_MISSING', "{$prefix}.product_clave_producto", 'Falta product_clave_producto (product.product_key).');
            }
            if (blank($item->product_clave_unidad)) {
                $errors[] = $this->error('INVOICE_ITEM_UNIT_KEY_MISSING', "{$prefix}.product_clave_unidad", 'Falta product_clave_unidad (product.unit_key).');
            }
            if (blank($item->product_objeto_imp)) {
                $errors[] = $this->error('INVOICE_ITEM_TAX_OBJECT_MISSING', "{$prefix}.product_objeto_imp", 'Falta product_objeto_imp (product.taxability).');
            }

            if ($item->tax_code !== null && ! in_array((string) $item->tax_code, self::RECOGNIZED_SAT_TAX_CODES, true)) {
                $errors[] = $this->error('INVOICE_ITEM_TAX_CODE_UNRECOGNIZED', "{$prefix}.tax_code", "Código SAT c_Impuesto no reconocido: '{$item->tax_code}'.");
            }

            // `InvoiceItem::tax_type` NO está cast al enum TaxType (a
            // diferencia de TaxRate::tax_type) — es un string plano en
            // este modelo. Comparar contra la instancia del enum nunca
            // sería cierto; se compara contra su valor literal. (Bug
            // preexistente detectado aquí — la misma comparación vivía,
            // sin ejercitarse por ninguna prueba real, en
            // FacturapiProvider::assertSnapshotIsComplete() antes de
            // Fase 6.2.3.)
            if ($item->tax_type === TaxType::Retencion->value) {
                $errors[] = $this->error('INVOICE_ITEM_WITHHOLDING_UNSUPPORTED', "{$prefix}.tax_type", 'El objeto Tax documentado de Facturapi no distingue traslado/retención — no soportado todavía.');
            }
        }
    }

    /**
     * @return array{code: string, field: string, message: string}
     */
    private function error(string $code, string $field, string $message): array
    {
        return ['code' => $code, 'field' => $field, 'message' => $message];
    }
}
