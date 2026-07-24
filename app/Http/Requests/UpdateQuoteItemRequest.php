<?php

namespace App\Http\Requests;

use App\Support\Tenant\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQuoteItemRequest extends FormRequest
{
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
        ];
    }
}
