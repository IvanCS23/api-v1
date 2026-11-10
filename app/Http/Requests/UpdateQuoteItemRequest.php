<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesLineTaxes;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateQuoteItemRequest extends FormRequest
{
    use ValidatesLineTaxes;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Igual que UpdateSaleItemRequest: mismos 6 campos editables, mismas
     * reglas de pertenencia de empresa para `product_id` y catálogo
     * global para `tax_rate_id`.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(CurrentTenant::class)->id();

        return [
            'product_id' => ['sometimes', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'description' => ['sometimes', 'string', 'max:255'],
            'quantity' => ['sometimes', 'numeric', 'min:0.001'],
            'unit_price' => ['sometimes', 'numeric', 'min:0'],
            'discount' => ['sometimes', 'numeric', 'min:0'],
            'tax_rate_id' => ['sometimes', 'nullable', 'integer', 'exists:tax_rates,id'],
            // Fase 6.34: varios impuestos por línea (ver ValidatesLineTaxes).
            ...$this->taxesRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validateTaxRules($validator));
    }
}
