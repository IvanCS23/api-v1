<?php

namespace App\Http\Requests;

use App\Enums\CfdiCancellationMotive;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class CancelInvoiceBusinessRequest extends FormRequest
{
    private const RESERVED_FIELDS = ['company_id', 'pac_external_id', 'cfdi_uuid', 'pac_status', 'status'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'confirm' => ['required', 'accepted'],
            'motive' => ['nullable', new Enum(CfdiCancellationMotive::class)],
            'substitution_uuid' => [
                'nullable',
                'required_if:motive,01',
                'prohibited_unless:motive,01',
                'uuid',
            ],
            'company_id' => ['prohibited'],
            'pac_external_id' => ['prohibited'],
            'cfdi_uuid' => ['prohibited'],
            'pac_status' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (self::RESERVED_FIELDS as $field) {
                if ($this->exists($field)) {
                    $validator->errors()->add($field, 'Este campo no puede enviarse en una operación empresarial.');
                }
            }
        });
    }
}
