<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Fase 6.27b — Subida del CSD: .cer y .key de hasta 64 KB y la contraseña
 * de la llave. Nada de esto se guarda; `csd_password`, `cer` y `key` están
 * en dontFlash. La organización y la empresa nunca vienen en la petición.
 */
class UploadCompanyCsdRequest extends FormRequest
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
            'cer' => ['required', 'file', 'extensions:cer', 'max:64'],
            'key' => ['required', 'file', 'extensions:key', 'max:64'],
            'csd_password' => ['required', 'string', 'max:255'],
            'organization_id' => ['prohibited'],
            'company_id' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cer.required' => 'Selecciona el archivo .cer del CSD.',
            'cer.extensions' => 'El certificado debe ser un archivo .cer.',
            'cer.max' => 'El archivo .cer no puede pesar más de 64 KB.',
            'key.required' => 'Selecciona el archivo .key del CSD.',
            'key.extensions' => 'La llave privada debe ser un archivo .key.',
            'key.max' => 'El archivo .key no puede pesar más de 64 KB.',
            'csd_password.required' => 'Escribe la contraseña de la llave privada.',
        ];
    }
}
