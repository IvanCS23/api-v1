<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * El login social no puede continuar. `$reason` es un código estable que
 * recibe el frontend (?error=...) para mostrar su propio mensaje.
 */
class SocialLoginException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::messageFor($reason));
    }

    public static function messageFor(string $reason): string
    {
        return match ($reason) {
            'account_not_found' => 'No existe una cuenta de FacturaPro con ese correo. Pide a un administrador que te dé de alta.',
            'role_not_allowed' => 'El inicio de sesión con Google o Microsoft solo está disponible para cuentas de cliente.',
            'account_inactive' => 'Esta cuenta se encuentra desactivada. Contacta a un administrador.',
            'email_not_verified' => 'El proveedor no confirmó que ese correo te pertenece.',
            'invalid_state' => 'La solicitud de inicio de sesión expiró o no es válida. Inténtalo de nuevo.',
            'access_denied' => 'Cancelaste el inicio de sesión.',
            'invalid_code' => 'El código de inicio de sesión no es válido o ya expiró.',
            default => 'No se pudo iniciar sesión con el proveedor. Inténtalo de nuevo.',
        };
    }
}
