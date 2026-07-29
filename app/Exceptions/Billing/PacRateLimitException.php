<?php

namespace App\Exceptions\Billing;

/**
 * Mapea respuestas 429 del PAC: límite de tasa excedido.
 */
class PacRateLimitException extends PacException
{
}
