<?php

namespace App\Http\Requests;

use App\Concerns\PasswordValidationRules;
use App\Http\Controllers\Api\PasswordRecoveryController;
use Illuminate\Foundation\Http\FormRequest;

/** Fase 6.32: POST /api/password/reset, con las mismas reglas de contraseña. */
class ResetPasswordRequest extends FormRequest
{
    use PasswordValidationRules;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => $this->passwordRules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        // Sin token o correo, el enlace está incompleto: mismo mensaje que
        // uno inválido.
        $invalidLink = PasswordRecoveryController::INVALID_MESSAGE;

        return [
            ...$this->passwordMessages(),
            'token.*' => $invalidLink,
            'email.*' => $invalidLink,
        ];
    }
}
