<?php

namespace App\Exceptions\Billing;

/**
 * Mapea respuestas 401/403 del PAC: credenciales inválidas o sin
 * permiso. Nunca incluye la API key usada en la petición.
 */
class PacAuthenticationException extends PacException
{
}
