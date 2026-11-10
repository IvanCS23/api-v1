<?php

namespace App\Services\Taxes;

use App\Models\Product;
use App\Models\Scopes\CompanyScope;
use App\Models\TaxRate;
use Illuminate\Database\Eloquent\Model;

/**
 * Fase 6.34 (5): de dónde salen los impuestos de una línea y cómo se guardan.
 *
 * Al crear una línea (D: compatibilidad con la pantalla actual):
 * - si la petición trae `taxes`, esos;
 * - si trae `tax_rate_id` (aunque sea nulo), el impuesto único de antes o
 *   ninguno, como hasta ahora;
 * - si no trae ninguno, los del producto (product_taxes).
 *
 * Al guardar, reemplaza todas las filas de impuestos de la línea; cada fila
 * lleva el company_id de la línea.
 */
class LineTaxWriter
{
    /**
     * @param  list<TaxSpec>|null  $requested
     * @return list<TaxSpec>
     */
    public function specsForNewLine(Product $product, ?array $requested, bool $taxRateSent, ?TaxRate $taxRate): array
    {
        if ($requested !== null) {
            return $requested;
        }
        if ($taxRateSent) {
            return $taxRate !== null ? [TaxSpec::fromTaxRate($taxRate)] : [];
        }

        return TaxSpec::fromModels($product->taxes()->get());
    }

    /**
     * Reemplaza los impuestos de una línea o producto por estas filas
     * (las de LineTaxCalculator, o las de un TaxSpec para productos).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function replace(Model $owner, array $rows): void
    {
        $relation = $owner->taxes();
        // La relación ya filtra por la línea; sin el scope de empresa para
        // funcionar también sin tenant activo (convertidores).
        $relation->withoutGlobalScope(CompanyScope::class)->delete();

        foreach (array_values($rows) as $position => $row) {
            $tax = $relation->make();
            $tax->forceFill([
                ...array_intersect_key($row, array_flip(['tax', 'kind', 'factor', 'rate', 'ieps_mode', 'base', 'amount', 'amount_rounded'])),
                'position' => $row['position'] ?? $position,
                'company_id' => $owner->getAttribute('company_id'),
            ])->save();
        }

        $owner->setRelation('taxes', $owner->taxes()->withoutGlobalScope(CompanyScope::class)->get());
    }

    /**
     * Las filas de impuestos de una línea, tal cual, para copiarlas a la
     * siguiente etapa (cotización → venta → factura). Sin global scopes,
     * como los convertidores. Una línea anterior a la 6.34 sin filas toma
     * su `tax_rate_id` con las cifras guardadas (sin recalcular).
     *
     * @param  class-string<Model>  $taxModel
     * @return list<array<string, mixed>>
     */
    public function rowsToCopy(Model $line, string $taxModel, string $foreignKey): array
    {
        $taxes = $taxModel::withoutGlobalScope(CompanyScope::class)
            ->where($foreignKey, $line->getKey())
            ->orderBy('position')->orderBy('id')
            ->get();

        if ($taxes->isNotEmpty()) {
            return $taxes->map(fn (Model $tax): array => [
                'tax' => $tax->getAttribute('tax'),
                'kind' => $tax->getAttribute('kind'),
                'factor' => $tax->getAttribute('factor'),
                'rate' => $tax->getRawOriginal('rate'),
                'ieps_mode' => $tax->getAttribute('ieps_mode'),
                'position' => $tax->getAttribute('position'),
                'base' => $tax->getRawOriginal('base'),
                'amount' => $tax->getRawOriginal('amount'),
                'amount_rounded' => $tax->getRawOriginal('amount_rounded'),
            ])->values()->all();
        }

        $taxRate = $line->getAttribute('tax_rate_id') !== null ? TaxRate::find($line->getAttribute('tax_rate_id')) : null;
        if ($taxRate === null) {
            return [];
        }

        $spec = TaxSpec::fromTaxRate($taxRate);
        $exempt = $spec->rate === null;
        $base = DecimalMath::max(DecimalMath::sub(DecimalMath::of($line->getRawOriginal('subtotal')), DecimalMath::of($line->getRawOriginal('discount'))), '0');
        $amount = DecimalMath::round(DecimalMath::of($line->getRawOriginal('tax_total')), 2);

        return [[
            ...$spec->toArray(),
            'position' => 0,
            'base' => DecimalMath::round($base, 6),
            'amount' => $exempt ? null : $amount,
            'amount_rounded' => $exempt ? '0.00' : $amount,
        ]];
    }

    /**
     * Para productos: sólo la definición de cada impuesto.
     *
     * @param  list<TaxSpec>  $specs
     */
    public function replaceProductTaxes(Product $product, array $specs): void
    {
        $this->replace($product, array_map(fn (TaxSpec $spec): array => $spec->toArray(), $specs));
    }
}
