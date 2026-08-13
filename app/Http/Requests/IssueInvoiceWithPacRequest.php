<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class IssueInvoiceWithPacRequest extends FormRequest
{
    private const RESERVED_FIELDS = [
        'company_id',
        'pac_external_id',
        'pac_draft_external_id',
        'cfdi_uuid',
        'status',
        'payment_form',
        'items',
        'customer',
        'payload',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'confirm' => ['required', 'accepted'],
            'company_id' => ['prohibited'],
            'pac_external_id' => ['prohibited'],
            'pac_draft_external_id' => ['prohibited'],
            'cfdi_uuid' => ['prohibited'],
            'status' => ['prohibited'],
            'payment_form' => ['prohibited'],
            'items' => ['prohibited'],
            'customer' => ['prohibited'],
            'payload' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (self::RESERVED_FIELDS as $field) {
                if ($this->exists($field)) {
                    $validator->errors()->add($field, 'Este campo no puede enviarse en una acción fiscal.');
                }
            }
        });
    }
}
