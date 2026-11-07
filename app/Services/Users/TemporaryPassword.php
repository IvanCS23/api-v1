<?php

namespace App\Services\Users;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

/**
 * Fase 6.32: contraseña temporal que genera el sistema cuando un Admin
 * restablece la de un usuario. Cumple las mismas reglas que cualquier
 * contraseña elegida a mano (Password::default(); en producción 12
 * caracteres, mayúsculas y minúsculas, números, símbolos y no filtrada).
 *
 * Str::password() garantiza una letra, un número y un símbolo, pero no
 * mayúsculas y minúsculas a la vez: por eso se valida y, si no cumple, se
 * genera otra.
 */
final class TemporaryPassword
{
    public const LENGTH = 16;

    private const MAX_ATTEMPTS = 20;

    public static function generate(): string
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $candidate = Str::password(self::LENGTH);

            if (Validator::make(['password' => $candidate], ['password' => [Password::default()]])->passes()) {
                return $candidate;
            }
        }

        throw new RuntimeException('No se pudo generar una contraseña temporal que cumpla las reglas.');
    }
}
