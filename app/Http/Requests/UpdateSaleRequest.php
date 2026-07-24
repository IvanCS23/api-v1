<?php

namespace App\Http\Requests;

use App\Support\Tenant\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Fase 4 §5: las transiciones de estado ya NO pasan por este
     * endpoint genérico — solo por SaleController::submit()/confirm()/
     * cancel() (ver SaleWorkflow). Por eso `status` fue retirado de las
     * reglas. Igual que `company_id`, `folio`, `created_by`,
     * `confirmed_at`, `cancelled_at`: al no declarar una regla para
     * ellos, `validated()` nunca los incluye — un payload que los envíe
     * los ignora en silencio (mismo contrato ya establecido para
     * `company_id` desde Fase 1), nunca produce un 422 ni cambia el
     * estado. Los totales tampoco son editables aquí — siempre los
     * recalcula SaleCalculator a partir de las líneas.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(CurrentTenant::class)->id();

        return [
            'client_id' => ['sometimes', 'required', 'integer', Rule::exists('clients', 'id')->where('company_id', $companyId)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
