<?php

namespace App\Services\Taxes;

use App\Enums\TaxFactorType;
use App\Enums\TaxType;
use Illuminate\Support\Facades\DB;

/**
 * Fase 6.34 (2): pasa el impuesto único de antes (`tax_rate_id` y, en la
 * factura, su snapshot `tax_*`) a las tablas de impuestos por producto y por
 * línea, y llena `taxes_transferred` / `taxes_withheld`.
 *
 * - Copia las cifras guardadas tal cual: no recalcula nada. `amount` y
 *   `amount_rounded` toman el `tax_total` de la línea; `base` es
 *   subtotal − descuento.
 * - Sin global scopes (DB::table): recorre todas las empresas, incluidas las
 *   líneas borradas en suave.
 * - Idempotente: omite lo que ya tiene impuestos o totales nuevos.
 * - Si encuentra una línea con retención, no escribe nada: el cálculo de
 *   antes la sumaba como traslado y su total no es confiable.
 * - Las columnas viejas de `products` (iva, iva_retenido, ieps, isr...) no se
 *   leen: el producto 2 de desarrollo se vuelve a capturar a mano (6.34, D4).
 */
class LineTaxBackfill
{
    /** @var array<string, array{0: string, 1: string}> tabla de líneas => [tabla de impuestos, llave] */
    public const LINE_TABLES = [
        'quote_items' => ['quote_item_taxes', 'quote_item_id'],
        'sale_items' => ['sale_item_taxes', 'sale_item_id'],
        'invoice_items' => ['invoice_item_taxes', 'invoice_item_id'],
    ];

    public const DOCUMENT_TABLES = ['quotes', 'sales', 'invoices'];

    /**
     * @return array{product_taxes: list<array<string, mixed>>, line_taxes: array<string, list<array<string, mixed>>>, line_totals: array<string, list<int>>, document_totals: array<string, list<int>>, conflicts: list<string>}
     */
    public function plan(): array
    {
        $rates = DB::table('tax_rates')->get()->keyBy('id');
        $plan = ['product_taxes' => [], 'line_taxes' => [], 'line_totals' => [], 'document_totals' => [], 'conflicts' => []];

        $products = DB::table('products')
            ->whereNotNull('tax_rate_id')
            ->whereNotExists(fn ($query) => $query->from('product_taxes')->whereColumn('product_taxes.product_id', 'products.id'))
            ->orderBy('id')
            ->get(['id', 'company_id', 'tax_rate_id']);

        foreach ($products as $product) {
            $rate = $rates[$product->tax_rate_id] ?? null;
            if ($rate === null) {
                $plan['conflicts'][] = "products #{$product->id}: tax_rate_id {$product->tax_rate_id} no existe";

                continue;
            }
            $plan['product_taxes'][] = ['company_id' => $product->company_id, 'product_id' => $product->id, ...$this->spec($rate->code, $rate->tax_type, $rate->factor_type, $rate->rate)];
        }

        foreach (self::LINE_TABLES as $table => [$taxTable, $foreignKey]) {
            $plan['line_taxes'][$table] = [];
            $plan['line_totals'][$table] = [];

            $columns = ['id', 'company_id', 'tax_rate_id', 'subtotal', 'discount', 'tax_total', 'taxes_transferred', 'taxes_withheld'];
            if ($table === 'invoice_items') {
                array_push($columns, 'tax_code', 'tax_type', 'tax_factor_type', 'tax_rate_value');
            }

            foreach (DB::table($table)->orderBy('id')->get($columns) as $line) {
                $hasTaxes = DB::table($taxTable)->where($foreignKey, $line->id)->exists();
                $spec = $this->lineSpec($table, $line, $rates);

                if ($spec !== null && $spec['kind'] === TaxType::Retencion->value) {
                    $plan['conflicts'][] = "{$table} #{$line->id}: tiene una retención; el cálculo anterior la sumaba como traslado";

                    continue;
                }

                if ($spec !== null && ! $hasTaxes) {
                    $exempt = $spec['factor'] === TaxFactorType::Exento->value;
                    $plan['line_taxes'][$table][] = [
                        'company_id' => $line->company_id,
                        $foreignKey => $line->id,
                        ...$spec,
                        'base' => $this->base($line),
                        'amount' => $exempt ? null : $this->money($line->tax_total),
                        'amount_rounded' => $exempt ? '0.00' : $this->money($line->tax_total),
                    ];
                }

                if ($this->needsTotals($line)) {
                    $plan['line_totals'][$table][] = (int) $line->id;
                }
            }
        }

        foreach (self::DOCUMENT_TABLES as $table) {
            $plan['document_totals'][$table] = DB::table($table)
                ->where('tax_total', '!=', 0)->where('taxes_transferred', 0)->where('taxes_withheld', 0)
                ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return $plan;
    }

    /**
     * Escribe el plan en una transacción. No toca `updated_at` de las líneas
     * ni de los documentos.
     *
     * @param  array<string, mixed>  $plan
     */
    public function apply(array $plan): void
    {
        if ($plan['conflicts'] !== []) {
            throw new \RuntimeException('Hay líneas que revisar a mano; no se escribió nada.');
        }

        DB::transaction(function () use ($plan): void {
            $now = now();
            foreach (array_chunk($plan['product_taxes'], 500) as $rows) {
                DB::table('product_taxes')->insert(array_map(fn ($row) => [...$row, 'created_at' => $now, 'updated_at' => $now], $rows));
            }

            foreach (self::LINE_TABLES as $table => [$taxTable]) {
                foreach (array_chunk($plan['line_taxes'][$table], 500) as $rows) {
                    DB::table($taxTable)->insert(array_map(fn ($row) => [...$row, 'created_at' => $now, 'updated_at' => $now], $rows));
                }
                foreach (array_chunk($plan['line_totals'][$table], 500) as $ids) {
                    DB::table($table)->whereIn('id', $ids)->update(['taxes_transferred' => DB::raw('tax_total'), 'taxes_withheld' => 0]);
                }
            }

            foreach ($plan['document_totals'] as $table => $ids) {
                foreach (array_chunk($ids, 500) as $chunk) {
                    DB::table($table)->whereIn('id', $chunk)->update(['taxes_transferred' => DB::raw('tax_total'), 'taxes_withheld' => 0]);
                }
            }
        });
    }

    /**
     * El impuesto de la línea: el snapshot de la factura si existe; si no, su
     * `tax_rate_id`.
     *
     * @return array{tax: string, kind: string, factor: string, rate: ?string, ieps_mode: ?string, position: int}|null
     */
    private function lineSpec(string $table, object $line, $rates): ?array
    {
        if ($table === 'invoice_items' && $line->tax_code !== null) {
            return $this->spec($line->tax_code, $line->tax_type, $line->tax_factor_type, $line->tax_rate_value);
        }

        if ($line->tax_rate_id === null || ! isset($rates[$line->tax_rate_id])) {
            return null;
        }

        $rate = $rates[$line->tax_rate_id];

        return $this->spec($rate->code, $rate->tax_type, $rate->factor_type, $rate->rate);
    }

    /** @return array{tax: string, kind: string, factor: string, rate: ?string, ieps_mode: ?string, position: int} */
    private function spec(?string $code, ?string $kind, ?string $factor, mixed $rate): array
    {
        $exempt = $factor === TaxFactorType::Exento->value;

        return [
            'tax' => (string) $code,
            'kind' => (string) $kind,
            'factor' => (string) $factor,
            'rate' => $exempt ? null : sprintf('%.6f', (float) $rate),
            'ieps_mode' => $code === '003' && $kind === TaxType::Traslado->value ? 'sum_before_taxes' : null,
            'position' => 0,
        ];
    }

    private function needsTotals(object $line): bool
    {
        return (float) $line->tax_total != 0.0 && (float) $line->taxes_transferred == 0.0 && (float) $line->taxes_withheld == 0.0;
    }

    private function base(object $line): string
    {
        $base = bcsub($this->money($line->subtotal), $this->money($line->discount), 6);

        return bccomp($base, '0', 6) < 0 ? '0.000000' : $base;
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
