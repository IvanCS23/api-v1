<?php

namespace App\Concerns;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords.
     *
     * @return array<int, Rule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        return ['required', 'string', Password::default(), 'confirmed'];
    }

    /**
     * Fase 6.32: mensajes en español de las reglas de la contraseña nueva
     * (APP_LOCALE es en). Las claves `password.password.*` son las que usa
     * la regla Password para mayúsculas, números, símbolos y filtradas.
     *
     * @return array<string, string>
     */
    protected function passwordMessages(): array
    {
        return [
            'password.required' => 'Escribe la contraseña nueva.',
            'password.min' => 'La contraseña debe tener al menos :min caracteres.',
            'password.confirmed' => 'La confirmación no coincide con la contraseña nueva.',
            'password.password.mixed' => 'La contraseña debe tener mayúsculas y minúsculas.',
            'password.password.letters' => 'La contraseña debe tener al menos una letra.',
            'password.password.numbers' => 'La contraseña debe tener al menos un número.',
            'password.password.symbols' => 'La contraseña debe tener al menos un símbolo.',
            'password.password.uncompromised' => 'Esta contraseña aparece en filtraciones de datos conocidas. Elige otra.',
        ];
    }

    /**
     * Get the validation rules used to validate the current password.
     *
     * @return array<int, Rule|array<mixed>|string>
     */
    protected function currentPasswordRules(): array
    {
        return ['required', 'string', 'current_password'];
    }
}
