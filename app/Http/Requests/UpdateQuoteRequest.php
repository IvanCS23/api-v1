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
     * `status` acepta cualquier valor de QuoteStatus EXCEPTO `converted`
     * — ese estado solo se alcanza a través de la conversión a venta
     * (QuoteToSaleConverter), nunca por una actualización genérica. El
     * controller además bloquea por completo cualquier update() cuando
     * la cotización ya no está en Draft/Sent (ver QuoteController).
     * company_id nunca se lee del payload.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(CurrentTenant::class)->id();

        return [
            'client_id' => ['sometimes', 'required', 'integer', Rule::exists('clients', 'id')->where('company_id', $companyId)],
            'notes' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::in(['draft', 'sent', 'approved', 'rejected', 'expired'])],
        ];
    }
}
