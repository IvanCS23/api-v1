<?php

namespace App\Exceptions\Billing;

/**
 * Mapea respuestas 500-599 del PAC: el proveedor no está disponible o
 * falló internamente. Reintentable en teoría (ver FacturapiProvider,
 * que ya reintenta errores transitorios seguros antes de llegar aquí).
 */
class PacUnavailableException extends PacException
{
}
