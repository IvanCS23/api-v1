<?php

namespace App\Http\Requests;

use App\Support\Tenant\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * folio/status/totales nunca se leen del payload: los calcula el
     * servidor (SaleNumberGenerator/SaleCalculator). company_id tampoco
     * — se obtiene de CurrentTenant, no del cliente.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(CurrentTenant::class)->id();

        return [
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('company_id', $companyId)],
            'notes' => ['nullable', 'string', 'max:1000'],
            'currency' => ['nullable', 'string', 'size:3'],
        ];
    }
}
