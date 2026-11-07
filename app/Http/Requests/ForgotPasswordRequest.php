<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Fase 6.32: POST /api/password/forgot. */
class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['email' => ['required', 'string', 'email', 'max:255']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.required' => 'Escribe el correo de tu cuenta.',
            'email.email' => 'Escribe un correo válido.',
            'email.max' => 'Escribe un correo válido.',
        ];
    }
}
