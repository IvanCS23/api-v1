<?php

namespace App\Http\Requests;

use App\Support\Tenant\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaleItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `product_id` debe pertenecer a la misma empresa (Rule::exists
     * escopado por company_id) — así se rechaza con 422 un intento de
     * agregar un producto de otra empresa, sin necesidad de tocar el
     * Global Scope. `unit_price` es opcional: si se omite, el controller
     * usa el precio del catálogo (products.precio_unitario). Los importes
     * (subtotal/tax_total/total) nunca se leen del payload — los calcula
     * SaleCalculator.
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
