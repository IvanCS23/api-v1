<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Fase 6.25: tasa de impuesto seleccionable en una línea de venta. */
class TaxRateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'rate' => $this->rate,
            'tax_type' => $this->tax_type?->value,
            'factor_type' => $this->factor_type?->value,
        ];
    }
}
