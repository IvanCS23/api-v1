<?php

namespace App\Infrastructure\Billing\Facturapi;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Throwable;

/**
 * Interpretación de las fechas que publica Facturapi (Fase 6.20).
 *
 * Facturapi no usa un único formato: `created_at` y `date` llegan en UTC
 * con sufijo `Z`, pero `stamp.date` (FechaTimbrado del TimbreFiscalDigital)
 * llega SIN offset y expresada en hora del centro de México, como exige el
 * Anexo 20. Parsearla con la zona de la aplicación (UTC) la guardaba 6 h
 * atrasada. Toda fecha sale de aquí normalizada a UTC.
 */
final class FacturapiDates
{
    public const DEFAULT_STAMP_TIMEZONE = 'America/Mexico_City';

    /**
     * `stamp.date`: sin offset se interpreta en `$timezone`
     * (`billing.facturapi.stamp_timezone`); si el texto trae `Z` u otro
     * offset explícito, ese offset manda y `$timezone` se ignora.
     */
    public static function stampDate(string $value, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::parse($value, $timezone)->utc();
    }

    /** Fechas que Facturapi documenta en UTC (`created_at`). */
    public static function utcDate(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'UTC')->utc();
    }

    public static function isValidTimezone(?string $timezone): bool
    {
        if ($timezone === null || $timezone === '') {
            return false;
        }

        try {
            new DateTimeZone($timezone);
        } catch (Throwable) {
            return false;
        }

        return true;
    }
}
