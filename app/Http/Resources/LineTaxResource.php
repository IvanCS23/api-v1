<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fase 6.34: un impuesto de producto o de línea. `base`, `amount` y
 * `amount_rounded` sólo existen en las líneas.
 */
class LineTaxResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return array_filter([
            'tax' => $this->tax,
            'kind' => $this->kind?->value,
            'factor' => $this->factor?->value,
            'rate' => $this->rate,
            'ieps_mode' => $this->ieps_mode,
            'position' => $this->position,
            'base' => $this->resource->getAttribute('base'),
            'amount' => $this->resource->getAttribute('amount'),
            'amount_rounded' => $this->resource->getAttribute('amount_rounded'),
        ], fn ($value, string $key): bool => ! in_array($key, ['base', 'amount', 'amount_rounded'], true) || array_key_exists($key, $this->resource->getAttributes()), ARRAY_FILTER_USE_BOTH);
    }
}
