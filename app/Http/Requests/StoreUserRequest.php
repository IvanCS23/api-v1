<?php

namespace App\Http\Requests;

use App\Concerns\PasswordValidationRules;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreUserRequest extends FormRequest
{
    use PasswordValidationRules;

    /** Antes de validar: un usuario que no es Admin no debe saber qué correos existen. */
    public function authorize(): bool
    {
        return (bool) $this->user('api')?->can('create', User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            // Fase 6.32: la contraseña temporal sigue las mismas reglas que
            // el cambio de contraseña (Password::defaults()).
            'password' => $this->passwordRules(),
            'role' => ['required', new Enum(UserRole::class)],
            'status' => ['nullable', new Enum(UserStatus::class)],
            'phone' => ['nullable', 'string', 'max:30'],
            'position' => ['nullable', 'string', 'max:255'],
            'branch' => ['nullable', 'string', 'max:255'],
            'must_change_password' => ['nullable', 'boolean'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->passwordMessages();
    }
}
