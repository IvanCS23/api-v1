<?php

namespace App\Enums;

/**
 * Clasificación de salud del ciclo ERP/PAC de una Invoice (Fase 6.18).
 * Es un diagnóstico derivado: nunca se persiste ni condiciona transiciones.
 *
 * - Valid: ERP y PAC son coherentes; no requiere acción.
 * - Transient: operación PAC en curso dentro de su ventana esperada.
 * - Recoverable: divergencia que una operación existente puede resolver.
 * - Invalid: combinación que ningún flujo debería producir; revisión manual.
 */
enum InvoiceHealthStatus: string
{
    case Valid = 'valid';
    case Transient = 'transient';
    case Recoverable = 'recoverable';
    case Invalid = 'invalid';

    public function severity(): int
    {
        return match ($this) {
            self::Valid => 0,
            self::Transient => 1,
            self::Recoverable => 2,
            self::Invalid => 3,
        };
    }

    public function isAtLeast(self $other): bool
    {
        return $this->severity() >= $other->severity();
    }

    public static function worst(self $a, self $b): self
    {
        return $a->severity() >= $b->severity() ? $a : $b;
    }
}
