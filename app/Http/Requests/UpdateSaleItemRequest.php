<?php

namespace App\Http\Requests;

use App\Support\Tenant\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSaleItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Solo estos 6 campos son editables (igual que en creación). `product_id`
     * y `tax_rate_id`, si se envían, deben pertenecer a la misma empresa /
     * al catálogo global respectivamente — mismas reglas que
     * StoreSaleItemRequest. Los importes calculados (subtotal/tax_total/
     * total) nunca se leen del payload, los recalcula SaleCalculator.
     * company_id, sale_id nunca se leen del payload.
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
