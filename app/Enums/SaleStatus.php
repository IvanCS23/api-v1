<?php

namespace App\Enums;

/**
 * Estados del ciclo de vida de una venta, previos a facturación.
 *
 * A propósito NO incluye `Invoiced` ni `Paid`: esos pertenecen a Fase 3
 * (Facturación/Facturapi) y Fase 2 (Cobros/Payments), respectivamente,
 * ninguna de las cuales existe todavía. Una venta `Confirmed` es una
 * venta comercialmente cerrada, lista para ser facturada o cobrada en
 * una fase posterior — no se modela esa transición aquí.
 */
enum SaleStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
}
