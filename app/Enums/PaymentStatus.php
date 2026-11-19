<?php

namespace App\Enums;

/**
 * Fase 6.35: estado de un pago recibido y su REP.
 *
 * - draft: capturado, sin REP.
 * - issued: REP timbrado. Sigue así aunque su cancelación esté pendiente o
 *   se haya rechazado (el pago sigue contando para el saldo).
 * - cancelled: el PAC confirmó la cancelación aceptada del REP.
 */
enum PaymentStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Cancelled = 'cancelled';
}
