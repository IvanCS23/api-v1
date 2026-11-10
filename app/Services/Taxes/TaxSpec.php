<?php

namespace App\Services\Taxes;

use App\Enums\TaxFactorType;
use App\Enums\TaxType;
use App\Models\TaxRate;
use Illuminate\Database\Eloquent\Model;

/**
 * Fase 6.34: un impuesto a aplicar en una línea, antes de calcularlo.
 *
 * - `tax`: clave de c_Impuesto (001 ISR, 002 IVA, 003 IEPS).
 * - `kind`: traslado o retención. `factor`: tasa, cuota o exento.
 * - `rate`: c_TasaOCuota con 6 decimales (nulo si es exento).
 * - `iepsMode`: sólo IEPS trasladado; en la 6.34 sólo `sum_before_taxes`
 *   (D1: el IEPS se desglosa y se suma a la base de los demás impuestos).
 */
final class TaxSpec
{
    public const IEPS_SUM_BEFORE_TAXES = 'sum_before_taxes';

    public function __construct(
        public readonly string $tax,
        public readonly TaxType $kind,
        public readonly TaxFactorType $factor,
        public readonly ?string $rate,
        public readonly ?string $iepsMode = null,
    ) {}

    /** El impuesto único de antes (`tax_rate_id`). */
    public static function fromTaxRate(TaxRate $rate): self
    {
        $exempt = $rate->factor_type === TaxFactorType::Exento;

        return new self(
            (string) $rate->code,
            $rate->tax_type,
            $rate->factor_type,
            $exempt ? null : DecimalMath::round(DecimalMath::of($rate->rate), 6),
            $rate->code === '003' && $rate->tax_type === TaxType::Traslado ? self::IEPS_SUM_BEFORE_TAXES : null,
        );
    }

    /** @param  array{tax: string, kind: string|TaxType, factor: string|TaxFactorType, rate?: mixed, ieps_mode?: ?string}  $data */
    public static function fromArray(array $data): self
    {
        $kind = $data['kind'] instanceof TaxType ? $data['kind'] : TaxType::from($data['kind']);
        $factor = $data['factor'] instanceof TaxFactorType ? $data['factor'] : TaxFactorType::from($data['factor']);
        $exempt = $factor === TaxFactorType::Exento;

        return new self(
            (string) $data['tax'],
            $kind,
            $factor,
            $exempt || ($data['rate'] ?? null) === null ? null : DecimalMath::round(DecimalMath::of($data['rate']), 6),
            $data['tax'] === '003' && $kind === TaxType::Traslado ? ($data['ieps_mode'] ?? self::IEPS_SUM_BEFORE_TAXES) : null,
        );
    }

    /** Un impuesto ya guardado (product_taxes o *_item_taxes). */
    public static function fromModel(Model $tax): self
    {
        return self::fromArray([
            'tax' => $tax->getAttribute('tax'),
            'kind' => $tax->getAttribute('kind'),
            'factor' => $tax->getAttribute('factor'),
            'rate' => $tax->getAttribute('rate'),
            'ieps_mode' => $tax->getAttribute('ieps_mode'),
        ]);
    }

    /**
     * @param  iterable<Model>  $taxes
     * @return list<self>
     */
    public static function fromModels(iterable $taxes): array
    {
        $specs = [];
        foreach ($taxes as $tax) {
            $specs[] = self::fromModel($tax);
        }

        return $specs;
    }

    public function isWithholding(): bool
    {
        return $this->kind === TaxType::Retencion;
    }

    public function isTransferredIeps(): bool
    {
        return $this->tax === '003' && $this->kind === TaxType::Traslado;
    }

    /** @return array{tax: string, kind: string, factor: string, rate: ?string, ieps_mode: ?string} */
    public function toArray(): array
    {
        return [
            'tax' => $this->tax,
            'kind' => $this->kind->value,
            'factor' => $this->factor->value,
            'rate' => $this->rate,
            'ieps_mode' => $this->iepsMode,
        ];
    }
}
