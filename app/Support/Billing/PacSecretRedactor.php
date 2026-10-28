<?php

namespace App\Support\Billing;

/**
 * Fase 6.27: tapa cualquier llave de Facturapi (sk_test_, sk_live_,
 * sk_user_) y cualquier `Bearer …` en un texto destinado a la BD, logs o
 * respuestas. Antes se reemplazaba sólo la llave global de `.env`; ahora
 * cada empresa tiene la suya, así que se reconoce por su forma, no por su
 * valor.
 */
final class PacSecretRedactor
{
    public static function redact(string $value): string
    {
        $value = preg_replace('/\bBearer\s+[^\s"}]+/i', '[redacted]', $value) ?? '[redacted]';

        return preg_replace('/\bsk_(?:live|test|user)_[A-Za-z0-9._-]+/i', '[redacted]', $value) ?? '[redacted]';
    }
}
