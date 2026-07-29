<?php

namespace App\Exceptions\Billing;

/**
 * Mapea respuestas 400/422 del PAC: el payload enviado fue rechazado por
 * datos inválidos (ej. RFC mal formado, uso de CFDI incompatible).
 */
class PacValidationException extends PacException
{
}
