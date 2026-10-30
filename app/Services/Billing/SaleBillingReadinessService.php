<?php

namespace App\Services\Billing;

use App\Enums\ProductType;
use App\Enums\SaleStatus;
use App\Enums\TaxType;
use App\Models\Company;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Scopes\CompanyScope;
use App\Services\Sat\SatFiscalValidator;
use App\Support\Billing\SalePaymentRules;
use Illuminate\Support\Collection;

/**
 * Evalúa si una Sale tiene todo lo necesario para, en una fase futura,
 * poder facturarse (timbrarse con Facturapi). Puramente de lectura: no
 * corrige nada, no llama ninguna API externa, no crea ninguna Invoice —
 * eso pertenece a una fase posterior no implementada todavía (Fase 3 del
 * roadmap original / ver ERP_ARCHITECTURE_PLAN.md §12, snapshot fiscal).
 *
 * Columnas auditadas antes de escribir este servicio (Fase 4 §1):
 * - clients: name, email, rfc, codigo_postal, regimen_fiscal, uso_cfdi.
 * - products: name, type, clave_producto, clave_unidad, descripcion.
 * - sales/sale_items: status, client_id, currency, subtotal/discount_total/
 *   tax_total/total, quantity, unit_price, product_id, tax_rate_id.
 *
 * Cada elemento de `errors` es {code, field, message}. `warnings` usa la
 * misma forma pero nunca afecta `ready` — son mejoras recomendadas, no
 * bloqueantes (ej. falta el correo del cliente). `clave_unidad` faltante
 * es warning por decisión explícita (ver checkItems()) — bloqueante para
 * el timbrado CFDI real cuando exista, no para este dominio interno.
 * `objeto_imp` faltante SÍ es bloqueante (auditoría Fase 5 — cierre).
 *
 * Las líneas se leen SIEMPRE con `withoutGlobalScope(CompanyScope::class)`
 * filtrando por `sale_id`: si se usara la relación `$sale->items()` tal
 * cual, una línea corrompida hacia otra empresa (ej. por un UPDATE directo
 * en BD) quedaría invisible para este servicio — el propio CompanyScope
 * la filtraría en silencio — y jamás se reportaría el TENANT_MISMATCH que
 * justamente se busca detectar.
 */
class SaleBillingReadinessService
{
    public function __construct(
        private readonly SatFiscalValidator $satValidator = new SatFiscalValidator,
    ) {}

    /**
     * @return array{ready: bool, errors: array<int, array{code: string, field: string, message: string}>, warnings: array<int, array{code: string, field: string, message: string}>}
     */
    public function evaluate(Sale $sale, bool $beforeInvoicing = false): array
    {
        $errors = [];
        $warnings = [];

        $items = SaleItem::withoutGlobalScope(CompanyScope::class)
            ->where('sale_id', $sale->id)
            ->get();

        $this->checkSale($sale, $items, $errors, $beforeInvoicing);
        $this->checkClient($sale, $errors, $warnings);
        $this->checkItems($items, $errors, $warnings, $beforeInvoicing);
        $this->checkTenant($sale, $items, $errors);

        if ($beforeInvoicing) {
            $this->checkPayment($sale, $errors);
        }

        return [
            'ready' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  Collection<int, SaleItem>  $items
     * @param  array<int, array{code: string, field: string, message: string}>  $errors
     */
    private function checkSale(Sale $sale, Collection $items, array &$errors, bool $beforeInvoicing): void
    {
        if ($beforeInvoicing) {
            // Fase 6.25: la orquestación (POST /sales/{sale}/invoice) envía y
            // confirma la venta; borrador y pendiente pueden facturarse.
            if ($sale->status === SaleStatus::Cancelled) {
                $errors[] = $this->error('SALE_CANCELLED', 'status', 'La venta está cancelada y no puede facturarse.');
            }
        } elseif ($sale->status !== SaleStatus::Confirmed) {
            $errors[] = $this->error('SALE_NOT_CONFIRMED', 'status', 'La venta debe estar Confirmed para poder facturarse.');
        }

        if ($sale->client_id === null || $sale->client === null) {
            $errors[] = $this->error('SALE_CLIENT_MISSING', 'client_id', 'La venta no tiene un cliente válido asociado.');
        }

        if ($items->isEmpty()) {
            $errors[] = $this->error('SALE_NO_ITEMS', 'items', 'La venta no tiene ninguna línea.');
        }

        if (empty($sale->currency) || ! preg_match('/^[A-Z]{3}$/', (string) $sale->currency)) {
            $errors[] = $this->error('SALE_INVALID_CURRENCY', 'currency', 'La venta no tiene una moneda válida (código de 3 letras).');
        }

        if ($items->isNotEmpty()) {
            $expectedSubtotal = round((float) $items->sum('subtotal'), 2);
            $expectedDiscount = round((float) $items->sum('discount'), 2);
            $expectedTax = round((float) $items->sum('tax_total'), 2);
            $expectedTotal = round((float) $items->sum('total'), 2);

            $coherent = round((float) $sale->subtotal, 2) === $expectedSubtotal
                && round((float) $sale->discount_total, 2) === $expectedDiscount
                && round((float) $sale->tax_total, 2) === $expectedTax
                && round((float) $sale->total, 2) === $expectedTotal;

            if (! $coherent) {
                $errors[] = $this->error('SALE_TOTALS_MISMATCH', 'total', 'Los totales de la venta no coinciden con la suma de sus líneas.');
            }
        }
    }

    /**
     * @param  array<int, array{code: string, field: string, message: string}>  $errors
     * @param  array<int, array{code: string, field: string, message: string}>  $warnings
     */
    private function checkClient(Sale $sale, array &$errors, array &$warnings): void
    {
        $client = $sale->client;

        if ($client === null) {
            // Ya se reportó SALE_CLIENT_MISSING en checkSale(); sin
            // cliente no hay nada más que validar aquí.
            return;
        }

        if (empty($client->name)) {
            $errors[] = $this->error('CLIENT_NAME_MISSING', 'client.name', 'El cliente no tiene nombre o razón social.');
        }

        if (empty($client->rfc)) {
            $errors[] = $this->error('CLIENT_RFC_MISSING', 'client.rfc', 'El cliente no tiene RFC.');
        }

        if (empty($client->codigo_postal)) {
            $errors[] = $this->error('CLIENT_POSTAL_CODE_MISSING', 'client.codigo_postal', 'El cliente no tiene código postal.');
        }

        if (empty($client->regimen_fiscal)) {
            $errors[] = $this->error('CLIENT_FISCAL_REGIME_MISSING', 'client.regimen_fiscal', 'El cliente no tiene régimen fiscal.');
        }

        if (empty($client->uso_cfdi)) {
            $errors[] = $this->error('CLIENT_CFDI_USE_MISSING', 'client.uso_cfdi', 'El cliente no tiene uso de CFDI.');
        }

        if (empty($client->email)) {
            $warnings[] = $this->error('CLIENT_EMAIL_MISSING', 'client.email', 'El cliente no tiene correo electrónico para el envío del CFDI.');
        }

        // Fase 6.26: además de faltantes, valores inválidos según el SAT
        // (formato, catálogo y matriz régimen ↔ uso ↔ tipo de persona).
        foreach ($this->satValidator->clientIssues($client->only(['rfc', 'codigo_postal', 'regimen_fiscal', 'uso_cfdi'])) as $issue) {
            $errors[] = $this->error($issue['code'], "client.{$issue['field']}", $issue['message']);
        }

        // Fase 6.27b: con RFC genérico, el C.P. del cliente es el lugar de
        // expedición (el C.P. de la empresa).
        $issuerPostalCode = Company::query()->whereKey($sale->company_id)->value('postal_code');

        if ($this->satValidator->genericClientPostalCodeMismatch($client->rfc, $client->codigo_postal, $issuerPostalCode)) {
            $errors[] = $this->error('CLIENT_GENERIC_POSTAL_CODE_MISMATCH', 'client.codigo_postal', "Con un RFC genérico el código postal del cliente debe ser el lugar de expedición ({$issuerPostalCode}).");
        }
    }

    /**
     * @param  Collection<int, SaleItem>  $items
     * @param  array<int, array{code: string, field: string, message: string}>  $errors
     * @param  array<int, array{code: string, field: string, message: string}>  $warnings
     */
    private function checkItems(Collection $items, array &$errors, array &$warnings, bool $beforeInvoicing): void
    {
        foreach ($items as $item) {
            $prefix = "items.{$item->id}";
            $product = $item->product_id !== null
                ? Product::withoutGlobalScope(CompanyScope::class)->find($item->product_id)
                : null;

            if ($item->product_id === null || $product === null) {
                $errors[] = $this->error('ITEM_PRODUCT_MISSING', "{$prefix}.product_id", 'La línea no tiene un producto válido asociado.');
            }

            if (empty($item->description)) {
                $errors[] = $this->error('ITEM_DESCRIPTION_MISSING', "{$prefix}.description", 'La línea no tiene descripción.');
            }

            if ((float) $item->quantity <= 0) {
                $errors[] = $this->error('ITEM_INVALID_QUANTITY', "{$prefix}.quantity", 'La línea tiene una cantidad inválida (debe ser mayor a 0).');
            }

            if ((float) $item->unit_price < 0) {
                $errors[] = $this->error('ITEM_INVALID_UNIT_PRICE', "{$prefix}.unit_price", 'La línea tiene un precio unitario inválido (no puede ser negativo).');
            }

            if ($product !== null) {
                if (empty($product->clave_producto)) {
                    $errors[] = $this->error('ITEM_PRODUCT_SAT_KEY_MISSING', "{$prefix}.product.clave_producto", 'El producto de la línea no tiene clave de producto SAT.');
                }

                if (empty($product->objeto_imp)) {
                    $errors[] = $this->error('ITEM_PRODUCT_OBJETO_IMP_MISSING', "{$prefix}.product.objeto_imp", 'El producto de la línea no tiene objeto de impuesto (c_ObjetoImp) SAT.');
                }

                /**
                 * Decisión documentada (auditoría Fase 5 — cierre):
                 * `clave_unidad` se mantiene como warning, no como error,
                 * para el dominio Invoice interno — una Invoice Draft/Ready
                 * es un registro fiscal propio, no un CFDI todavía, y no
                 * bloquear por esto permite avanzar el flujo interno sin
                 * datos catalogados al 100%. Cuando se integre Facturapi y
                 * exista una emisión real de CFDI, `clave_unidad` faltante
                 * SÍ deberá bloquear esa emisión (el atributo `ClaveUnidad`
                 * es obligatorio en el nodo Concepto del CFDI) — ese cambio
                 * pertenece a la validación previa al timbrado, no a este
                 * servicio.
                 */
                // Fase 6.25: antes de facturar bloquea, porque la factura
                // quedaría ready sin poder emitirse (ClaveUnidad es obligatoria
                // en el CFDI; InvoicePacReadinessService la exige).
                if (empty($product->clave_unidad)) {
                    $unitKey = $this->error('ITEM_PRODUCT_UNIT_KEY_MISSING', "{$prefix}.product.clave_unidad", 'El producto de la línea no tiene clave de unidad SAT.');
                    if ($beforeInvoicing) {
                        $errors[] = $unitKey;
                    } else {
                        $warnings[] = $unitKey;
                    }
                }

                $this->checkProductCatalogs($product, $prefix, $errors, $warnings, $beforeInvoicing);

                try {
                    if (! $product->type instanceof ProductType) {
                        $errors[] = $this->error('ITEM_PRODUCT_TYPE_INVALID', "{$prefix}.product.type", 'El producto de la línea no tiene un tipo válido.');
                    }
                } catch (\ValueError) {
                    $errors[] = $this->error('ITEM_PRODUCT_TYPE_INVALID', "{$prefix}.product.type", 'El producto de la línea no tiene un tipo válido.');
                }
            }

            if ($item->tax_rate_id !== null) {
                $taxRate = $item->taxRate;

                if ($taxRate === null || $taxRate->active !== true) {
                    $errors[] = $this->error('ITEM_TAX_RATE_INVALID', "{$prefix}.tax_rate_id", 'La línea referencia una tasa de impuesto inexistente o inactiva.');
                } elseif ($beforeInvoicing && $taxRate->tax_type === TaxType::Retencion) {
                    // Fase 6.25: el PAC todavía no admite retenciones
                    // (INVOICE_ITEM_WITHHOLDING_UNSUPPORTED al emitir).
                    $errors[] = $this->error('ITEM_TAX_WITHHOLDING_UNSUPPORTED', "{$prefix}.tax_rate_id", 'La línea usa una retención, que todavía no se puede facturar.');
                }
            }

            if ($product !== null) {
                $this->checkTaxObject($item, $product, $prefix, $errors);
            }
        }
    }

    /**
     * Fase 6.26: clave de producto, unidad y objeto de impuesto que existen
     * pero no son válidos en los catálogos del SAT. La unidad inválida sigue
     * la misma severidad que la faltante (warning salvo antes de facturar).
     *
     * @param  array<int, array{code: string, field: string, message: string}>  $errors
     * @param  array<int, array{code: string, field: string, message: string}>  $warnings
     */
    private function checkProductCatalogs(Product $product, string $prefix, array &$errors, array &$warnings, bool $beforeInvoicing): void
    {
        $issues = $this->satValidator->productIssues($product->only(['clave_producto', 'clave_unidad', 'objeto_imp']));

        foreach ($issues as $issue) {
            $code = match ($issue['code']) {
                'PRODUCT_SAT_KEY_INVALID' => 'ITEM_PRODUCT_SAT_KEY_INVALID',
                'PRODUCT_UNIT_KEY_INVALID' => 'ITEM_PRODUCT_UNIT_KEY_INVALID',
                'PRODUCT_TAX_OBJECT_INVALID' => 'ITEM_PRODUCT_OBJETO_IMP_INVALID',
                // La coherencia que cuenta en la factura es la de la línea
                // (checkTaxObject), no el IVA sugerido del producto.
                default => null,
            };

            if ($code === null) {
                continue;
            }

            $entry = $this->error($code, "{$prefix}.product.{$issue['field']}", $issue['message']);

            if ($code === 'ITEM_PRODUCT_UNIT_KEY_INVALID' && ! $beforeInvoicing) {
                $warnings[] = $entry;
            } else {
                $errors[] = $entry;
            }
        }
    }

    /**
     * Fase 6.26: el objeto de impuesto del producto debe corresponder al
     * impuesto real de la línea, que es lo que llega al CFDI: con 02 la línea
     * necesita un traslado (16 %, 0 % o exento); con 01, 03 o 04 no puede
     * llevarlo. 05–08 no se validan en esta fase.
     *
     * @param  array<int, array{code: string, field: string, message: string}>  $errors
     */
    private function checkTaxObject(SaleItem $item, Product $product, string $prefix, array &$errors): void
    {
        $taxObject = (string) $product->objeto_imp;
        $hasTransfer = $item->tax_rate_id !== null && $item->taxRate?->tax_type === TaxType::Traslado;

        if ($taxObject === SatFiscalValidator::TAX_OBJECT_TAXED && ! $hasTransfer) {
            $errors[] = $this->error('ITEM_TAX_OBJECT_MISMATCH', "{$prefix}.tax_rate_id", 'El producto es objeto de impuesto (02) pero la línea no tiene IVA: elige 16 %, 0 % o exento.');
        } elseif (in_array($taxObject, SatFiscalValidator::TAX_OBJECTS_WITHOUT_TAX, true) && $hasTransfer) {
            $errors[] = $this->error('ITEM_TAX_OBJECT_MISMATCH', "{$prefix}.tax_rate_id", "El producto tiene objeto de impuesto {$taxObject}, que no lleva IVA, pero la línea sí lo tiene.");
        }
    }

    /**
     * Verificación explícita de aislamiento multiempresa (defensa en
     * profundidad): aunque CompanyScope ya impide estructuralmente que
     * una Sale cargue un Client/Product/SaleItem de otra empresa en el
     * uso normal de la aplicación, este servicio vuelve a confirmarlo
     * leyendo sin scope — el único escenario que detecta es una
     * corrupción de datos por fuera de Eloquent (BelongsToCompany ya
     * impide el cambio de company_id vía update() normal).
     *
     * @param  Collection<int, SaleItem>  $items
     * @param  array<int, array{code: string, field: string, message: string}>  $errors
     */
    private function checkTenant(Sale $sale, Collection $items, array &$errors): void
    {
        if ($sale->client !== null && $sale->client->company_id !== $sale->company_id) {
            $errors[] = $this->error('TENANT_MISMATCH', 'client_id', 'El cliente de la venta pertenece a otra empresa.');
        }

        foreach ($items as $item) {
            if ($item->company_id !== $sale->company_id) {
                $errors[] = $this->error('TENANT_MISMATCH', "items.{$item->id}.company_id", 'Una línea de la venta pertenece a otra empresa.');
            }

            $product = $item->product_id !== null
                ? Product::withoutGlobalScope(CompanyScope::class)->find($item->product_id)
                : null;

            if ($product !== null && $product->company_id !== $sale->company_id) {
                $errors[] = $this->error('TENANT_MISMATCH', "items.{$item->id}.product_id", 'El producto de una línea pertenece a otra empresa.');
            }
        }
    }

    /**
     * Fase 6.25: forma y método de pago efectivos (venta y, si falta, la
     * empresa) completos y compatibles (SalePaymentRules).
     *
     * @param  array<int, array{code: string, field: string, message: string}>  $errors
     */
    private function checkPayment(Sale $sale, array &$errors): void
    {
        $company = Company::find($sale->company_id);
        [$form, $method] = SalePaymentRules::effective(
            $sale->payment_form,
            $sale->payment_method,
            $company?->default_payment_form,
            $company?->default_payment_method,
        );

        if ($form === null) {
            $errors[] = $this->error('SALE_PAYMENT_FORM_MISSING', 'payment_form', 'La venta no tiene forma de pago (ni la empresa una predeterminada).');
        } elseif (! SalePaymentRules::isValidForm($form)) {
            // Fase 6.26: p. ej. un predeterminado de empresa capturado antes
            // de validar contra c_FormaPago.
            $errors[] = $this->error(SalePaymentRules::FORM_INVALID, 'payment_form', "La forma de pago {$form} no existe en el catálogo c_FormaPago del SAT.");
        }

        if ($method === null) {
            $errors[] = $this->error('SALE_PAYMENT_METHOD_MISSING', 'payment_method', 'La venta no tiene método de pago (ni la empresa uno predeterminado).');
        } elseif (! SalePaymentRules::isMethodAvailable($method)) {
            // Fase 6.28: también cubre un PPD heredado de la empresa o
            // guardado antes del bloqueo. La regla PPD ↔ 99 ya no aplica.
            $errors[] = $this->error(SalePaymentRules::PPD_NOT_AVAILABLE, 'payment_method', SalePaymentRules::PPD_NOT_AVAILABLE_MESSAGE);

            return;
        }

        $violation = SalePaymentRules::violation($form, $method);

        if ($violation !== null) {
            $errors[] = $this->error($violation, 'payment_form', SalePaymentRules::message($violation));
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
