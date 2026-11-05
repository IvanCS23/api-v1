<?php

namespace App\Support\Billing;

use Carbon\CarbonImmutable;

/**
 * Fase 6.31 — Hasta cuándo una cancelación pendiente es "reciente" en
 * billing:health.
 *
 * El receptor tiene tres días hábiles para aceptar o rechazar (no 72 h de
 * reloj). Se cuentan de lunes a viernes en la hora del centro de México
 * (billing.facturapi.stamp_timezone) desde la solicitud, y se suma un
 * margen operativo propio (24 h por omisión): el ERP sólo conoce el
 * desenlace al reconciliar. Los días festivos oficiales no se modelan, así
 * que el hallazgo puede escalar antes de tiempo; nunca bloquea una
 * operación.
 */
final class CancellationReceiverDeadline
{
    public static function for(CarbonImmutable $requestedAt): CarbonImmutable
    {
        $timezone = (string) config('billing.facturapi.stamp_timezone', 'America/Mexico_City');
        $businessDays = max(1, (int) config('billing.health.cancellation_receiver_business_days', 3));
        $marginHours = max(0, (int) config('billing.health.cancellation_margin_hours', 24));

        $deadline = $requestedAt->setTimezone($timezone);
        $counted = 0;

        while ($counted < $businessDays) {
            $deadline = $deadline->addDay();

            if (! $deadline->isWeekend()) {
                $counted++;
            }
        }

        return $deadline->addHours($marginHours)->utc();
    }
}
