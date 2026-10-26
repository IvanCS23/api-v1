<?php

namespace App\Http\Requests\Concerns;

use App\Enums\TaxFactorType;
use App\Enums\TaxType;
use App\Models\TaxRate;
use App\Services\Sat\SatFiscalValidator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Fase 6.26: clave de producto y unidad contra los catálogos del SAT, objeto
 * de impuesto contra c_ObjetoImp y su coherencia con el IVA del producto.
 *
 * El IVA se captura como `tax_rate_id` (IVA 16 %, IVA 0 % o IVA Exento de
 * tax_rates): un decimal no distingue exento de tasa 0 %. `iva` se sigue
 * aceptando por compatibilidad (16 o 0 se traducen a su tasa), pero ya no se
 * guarda tal cual: se deriva de la tasa (16, 0; exento o sin IVA → 0).
 *
 * Igual que en clientes, se valida la combinación final; editar sólo datos
 * no fiscales (nombre, precio, descripción) no exige corregir lo guardado.
 */
trait ValidatesProductFiscalData
{
    private const FISCAL_FIELDS = ['clave_producto', 'clave_unidad', 'objeto_imp', 'tax_rate_id'];

    protected function normalizeFiscalData(): void
    {
        $data = [];

        foreach (['clave_producto', 'objeto_imp'] as $field) {
            if (is_string($this->input($field))) {
                $data[$field] = trim($this->input($field));
            }
        }

        if (is_string($this->input('clave_unidad'))) {
            $data['clave_unidad'] = mb_strtoupper(trim($this->input('clave_unidad')));
        }

        if (! $this->exists('tax_rate_id') && is_numeric($this->input('iva'))) {
            $data['tax_rate_id'] = $this->taxRateIdForLegacyIva((float) $this->input('iva'));
        }

        $this->merge($data);
    }

    /** @return array<string, mixed> */
    protected function fiscalRules(bool $creating): array
    {
        $required = $creating ? ['required'] : ['sometimes', 'required'];

        return [
            'clave_producto' => [...$required, 'string', 'size:8'],
            'clave_unidad' => [...$required, 'string', 'max:3'],
            'objeto_imp' => [...$required, 'string', 'max:2'],
            'tax_rate_id' => ['nullable', 'integer', Rule::exists('tax_rates', 'id')
                ->where('active', true)
                ->where('tax_type', TaxType::Traslado->value)
                ->where('code', '002')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'tax_rate_id.exists' => 'La tasa de IVA seleccionada no existe o no está activa.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $sent = array_filter(self::FISCAL_FIELDS, fn (string $field): bool => $this->exists($field));

            if ($sent === [] || $validator->errors()->hasAny(self::FISCAL_FIELDS)) {
                return;
            }

            $product = $this->currentFiscalData();
            foreach ($sent as $field) {
                $product[$field] = $this->input($field);
            }

            foreach (app(SatFiscalValidator::class)->productIssues($product) as $issue) {
                $validator->errors()->add($issue['field'], $issue['message']);
            }
        });
    }

    /**
     * `iva` derivado de la tasa cuando la petición la fija. Nunca se guarda el
     * `iva` crudo del payload.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function withDerivedIva(array $validated): array
    {
        unset($validated['iva']);

        if (array_key_exists('tax_rate_id', $validated)) {
            $taxRate = $validated['tax_rate_id'] === null ? null : TaxRate::find($validated['tax_rate_id']);
            $validated['iva'] = $taxRate !== null && $taxRate->factor_type === TaxFactorType::Tasa
                ? round((float) $taxRate->rate * 100, 2)
                : 0;
        }

        return $validated;
    }

    /**
     * Datos fiscales que el producto ya tenía (vacíos al crear).
     *
     * @return array<string, mixed>
     */
    protected function currentFiscalData(): array
    {
        return [];
    }

    /** 16 → IVA 16 %, 0 → IVA 0 % (tasa, nunca exento); otro valor → sin tasa. */
    private function taxRateIdForLegacyIva(float $iva): ?int
    {
        $rate = $iva > 1 ? $iva / 100 : $iva;

        return TaxRate::query()
            ->where('active', true)
            ->where('code', '002')
            ->where('tax_type', TaxType::Traslado->value)
            ->where('factor_type', TaxFactorType::Tasa->value)
            ->get()
            ->first(fn (TaxRate $taxRate): bool => abs((float) $taxRate->rate - $rate) < 0.000001)
            ?->id;
    }
}
