<?php

namespace App\Http\Requests;

use App\Enums\SaleStatus;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `status` solo acepta los 4 valores de SaleStatus (nunca Invoiced ni
     * Paid, que no existen). Los totales NO son editables aquí — siempre
     * los recalcula SaleCalculator a partir de las líneas. company_id
     * nunca se lee del payload.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(CurrentTenant::class)->id();

        return [
            'client_id' => ['sometimes', 'required', 'integer', Rule::exists('clients', 'id')->where('company_id', $companyId)],
            'notes' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', new Enum(SaleStatus::class)],
        ];
    }
}
