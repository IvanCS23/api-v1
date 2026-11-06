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
}
