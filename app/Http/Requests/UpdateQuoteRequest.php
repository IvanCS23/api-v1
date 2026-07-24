<?php

namespace App\Http\Requests;

use App\Support\Tenant\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Fase 4 §5: las transiciones de estado ya NO pasan por este
     * endpoint genérico — solo por QuoteController::send()/approve()/
     * reject()/expire() (ver QuoteWorkflow), y Approved→Converted sigue
     * siendo responsabilidad exclusiva de QuoteToSaleConverter. Por eso
     * `status` fue retirado de las reglas. Igual que `company_id`,
     * `folio`, `created_by`, `converted_sale_id`, `approved_at`,
     * `converted_at`: al no declarar una regla para ellos, `validated()`
     * nunca los incluye — un payload que los envíe los ignora en
     * silencio (mismo contrato ya establecido para `company_id` desde
     * Fase 1), nunca produce un 422 ni cambia el estado.
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
