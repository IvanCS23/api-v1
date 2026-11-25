<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * CompanyAdminGuard rechazó el cambio (p. ej. dejar a la empresa sin
 * administrador activo). `$violation` es el payload exacto que devolvió el
 * guard; el controlador lo responde tal cual con 422.
 */
class CompanyAdminViolationException extends RuntimeException
{
    public function __construct(public readonly mixed $violation)
    {
        parent::__construct('El cambio dejaría a la empresa en un estado no permitido.');
    }
}
