<?php

namespace App\Http\Requests;

use App\Support\Tenant\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Únicamente `sale_id` — todo lo demás (folio, status, totales,
     * snapshot fiscal completo) lo produce SaleToInvoiceConverter, nunca
     * el payload. `sale_id` debe pertenecer a la misma empresa (422 si
     * no) — la pertenencia a otra empresa nunca debe filtrarse como un
     * 404 de scope ni como "venta no lista para facturar".
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(CurrentTenant::class)->id();

        return [
            'sale_id' => ['required', 'integer', Rule::exists('sales', 'id')->where('company_id', $companyId)],
        ];
    }
}
