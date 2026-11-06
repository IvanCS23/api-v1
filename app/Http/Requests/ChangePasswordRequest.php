<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Fase 6.32: mismas reglas que el resto (Password::defaults()).
            'password' => ['required', 'string', Password::default(), 'confirmed'],
            'must_change_password' => ['nullable', 'boolean'],
        ];
    }
}
