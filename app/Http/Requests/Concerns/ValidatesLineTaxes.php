<?php

namespace App\Http\Requests\Concerns;

use App\Enums\TaxFactorType;
use App\Enums\TaxType;
use App\Services\Taxes\TaxRuleValidator;
use App\Services\Taxes\TaxSpec;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Fase 6.34 (5): `taxes`, varios impuestos por producto o por línea:
 * [{tax: 001|002|003, kind: traslado|retencion, factor: tasa|cuota|exento,
 * rate: fracción (0.16), ieps_mode?}]. No se combina con `tax_rate_id` (el
 * impuesto único de antes, que se sigue aceptando por compatibilidad).
 * Además de la forma, aplica TaxRuleValidator (reglas del SAT y lista
 * cerrada de retenciones).
 */
trait ValidatesLineTaxes
{
    /** @return array<string, mixed> */
    protected function taxesRules(): array
    {
        return [
            'taxes' => ['sometimes', 'array', 'max:6', 'prohibits:tax_rate_id'],
            'taxes.*' => ['array:tax,kind,factor,rate,ieps_mode'],
            'taxes.*.tax' => ['required', 'string', Rule::in(['001', '002', '003'])],
            'taxes.*.kind' => ['required', Rule::enum(TaxType::class)],
            'taxes.*.factor' => ['required', Rule::enum(TaxFactorType::class)],
            'taxes.*.rate' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'taxes.*.ieps_mode' => ['nullable', 'string', 'max:30'],
        ];
    }

    protected function validateTaxRules(Validator $validator, ?string $taxObject = null): void
    {
        if (! $this->exists('taxes') || $validator->errors()->has('taxes') || $validator->errors()->has('taxes.*')) {
            return;
        }

        foreach ((new TaxRuleValidator)->issues($this->taxSpecs() ?? [], $taxObject) as $issue) {
            $validator->errors()->add($issue['field'], $issue['message']);
        }
    }

    /** @return list<TaxSpec>|null null si la petición no trae `taxes` */
    public function taxSpecs(): ?array
    {
        if (! $this->exists('taxes')) {
            return null;
        }

        return array_map(fn (array $tax): TaxSpec => TaxSpec::fromArray($tax), array_values((array) $this->input('taxes', [])));
    }
}
