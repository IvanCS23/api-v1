<?php

namespace App\Services\Billing;

use App\Enums\ProductType;
use App\Enums\SaleStatus;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Scopes\CompanyScope;
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
 * bloqueantes (ej. falta el correo del cliente, o la clave de unidad SAT
 * del producto).
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
    /**
     * @return array{ready: bool, errors: array<int, array{code: string, field: string, message: string}>, warnings: array<int, array{code: string, field: string, message: string}>}
     */
    public function evaluate(Sale $sale): array
    {
        $errors = [];
        $warnings = [];

        $items = SaleItem::withoutGlobalScope(CompanyScope::class)
            ->where('sale_id', $sale->id)
            ->get();

        $this->checkSale($sale, $items, $errors);
        $this->checkClient($sale, $errors, $warnings);
        $this->checkItems($items, $errors, $warnings);
        $this->checkTenant($sale, $items, $errors);

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
    private function checkSale(Sale $sale, Collection $items, array &$errors): void
    {
        if ($sale->status !== SaleStatus::Confirmed) {
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
    }

    /**
     * @param  Collection<int, SaleItem>  $items
     * @param  array<int, array{code: string, field: string, message: string}>  $errors
     * @param  array<int, array{code: string, field: string, message: string}>  $warnings
     */
    private function checkItems(Collection $items, array &$errors, array &$warnings): void
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

                if (empty($product->clave_unidad)) {
                    $warnings[] = $this->error('ITEM_PRODUCT_UNIT_KEY_MISSING', "{$prefix}.product.clave_unidad", 'El producto de la línea no tiene clave de unidad SAT.');
                }

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
                }
            }
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
     * @return array{code: string, field: string, message: string}
     */
    private function error(string $code, string $field, string $message): array
    {
        return ['code' => $code, 'field' => $field, 'message' => $message];
    }
}
