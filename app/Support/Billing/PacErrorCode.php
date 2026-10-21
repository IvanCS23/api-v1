<?php

namespace App\Support\Billing;

/**
 * Lee el código estable que los servicios anteponen a sus errores
 * persistidos (`[CODIGO] mensaje`). Fase 6.22: única regla para el
 * recurso de billing y billing:health; nunca expone el resto del texto.
 */
final class PacErrorCode
{
    public static function from(?string $lastError): ?string
    {
        if (! is_string($lastError)
            || preg_match('/^\[([A-Z0-9_.-]{1,100})\](?:\s|$)/', $lastError, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
