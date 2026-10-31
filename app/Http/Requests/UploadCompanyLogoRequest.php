<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Fase 6.29: logotipo del PDF. Facturapi acepta JPG o PNG de hasta 500 KB
 * (recomienda 800 × 500 px).
 */
class UploadCompanyLogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'logo' => ['required', 'file', 'mimes:jpg,jpeg,png', 'mimetypes:image/jpeg,image/png', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'logo.required' => 'Selecciona la imagen del logotipo.',
            'logo.file' => 'Selecciona la imagen del logotipo.',
            'logo.mimes' => 'El logotipo debe ser una imagen JPG o PNG.',
            'logo.mimetypes' => 'El logotipo debe ser una imagen JPG o PNG.',
            'logo.max' => 'El logotipo no puede pesar más de 500 KB.',
        ];
    }
}
