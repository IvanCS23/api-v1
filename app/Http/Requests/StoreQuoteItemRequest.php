<?php

namespace App\Http\Requests;

use App\Support\Tenant\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuoteItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Igual que StoreSaleItemRequest: `product_id` debe pertenecer a la
     * misma empresa (422 si no), `unit_price` es opcional (default al
     * precio de catálogo), importes nunca se leen del payload.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(CurrentTenant::class)->id();

        return [
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'tax_rate_id' => ['nullable', 'integer', 'exists:tax_rates,id'],
        ];
    }
}
