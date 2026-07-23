<?php

namespace App\Enums;

/**
 * Distingue impuestos trasladados (el emisor los cobra al receptor, ej.
 * IVA) de impuestos retenidos (el receptor los retiene, ej. ISR/IVA
 * retenido). VARCHAR + enum de PHP, no ENUM nativo de MySQL.
 */
enum TaxType: string
{
    case Traslado = 'traslado';
    case Retencion = 'retencion';
}
