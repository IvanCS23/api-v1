<?php

namespace App\Http\Requests;

use App\Concerns\PasswordValidationRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Fase 6.32: cambio de la propia contraseña (cualquier rol). Exige la
 * contraseña actual y aplica las mismas reglas que el resto de los caminos
 * (Password::defaults(): en producción 12 caracteres, mayúsculas y
 * minúsculas, números, símbolos y no filtrada).
 */
class UpdateMyPasswordRequest extends FormRequest
{
    use PasswordValidationRules;

    public function authorize(): bool
    {
        return $this->user('api') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:api'],
            'password' => [...$this->passwordRules(), 'different:current_password'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.current_password' => 'La contraseña actual no es correcta.',
            'password.different' => 'La contraseña nueva debe ser distinta de la actual.',
        ];
    }
}
