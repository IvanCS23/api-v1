<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Fase 6.27b — Formato básico de los datos fiscales. Las reglas del SAT
 * (RFC, régimen por tipo de persona, C.P., razón social) las aplica
 * UpdateCompanyFiscalDataService con issuerIssues, con mensajes por campo.
 *
 * La organización y la empresa nunca vienen en la petición: se rechazan.
 */
class UpdateCompanyFiscalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user('api');

        return $user !== null && $user->company !== null && $user->can('updateFiscal', $user->company);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'legal_name' => ['nullable', 'string', 'max:100'],
            'trade_name' => ['nullable', 'string', 'max:100'],
            'rfc' => ['nullable', 'string', 'max:13'],
            'fiscal_regime' => ['nullable', 'string', 'max:3'],
            'postal_code' => ['nullable', 'string', 'max:5'],
            'street' => ['nullable', 'string', 'max:255'],
            'external_number' => ['nullable', 'string', 'max:50'],
            'internal_number' => ['nullable', 'string', 'max:50'],
            'colony' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'municipality' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'organization_id' => ['prohibited'],
            'company_id' => ['prohibited'],
        ];
    }
}
