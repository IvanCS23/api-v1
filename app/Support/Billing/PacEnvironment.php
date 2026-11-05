<?php

namespace App\Support\Billing;

use App\Models\FacturapiOrganization;
use App\Models\Invoice;

/**
 * Fase 6.31 — Ambiente del PAC (`test` o `live`).
 *
 * Es la única clase que lee `billing.pac_mode` (una prueba lo exige). Ese
 * valor ya no es "el modo" de todo el sistema sino su techo:
 *
 * - `test`: toda empresa opera sólo en Test. Una empresa en Live se
 *   rechaza con `pac_live_not_enabled` (paro de emergencia), nunca usa
 *   su llave Test como respaldo.
 * - `live`: cada empresa opera en el ambiente de su organización.
 * - vacío o desconocido: `pac_mode_unsupported`.
 *
 * Live exige además que APP_ENV esté en `billing.live_allowed_app_envs`
 * (por omisión sólo `production`, sin excepción para `testing`).
 */
final class PacEnvironment
{
    public const TEST = 'test';

    public const LIVE = 'live';

    public const ALL = [self::TEST, self::LIVE];

    public static function isValid(mixed $environment): bool
    {
        return is_string($environment) && in_array($environment, self::ALL, true);
    }

    /** Techo configurado (`billing.pac_mode`), o null si no es válido. */
    public static function ceiling(): ?string
    {
        $value = config('billing.pac_mode');

        return self::isValid($value) ? $value : null;
    }

    public static function liveAllowedInThisAppEnv(): bool
    {
        $allowed = config('billing.live_allowed_app_envs');

        return is_array($allowed) && $allowed !== [] && in_array(app()->environment(), $allowed, true);
    }

    /**
     * Motivo por el que no se puede operar en ese ambiente, o null.
     * Orden: techo inválido, ambiente inválido, Live sin habilitar, Live
     * fuera de los APP_ENV permitidos.
     */
    public static function violation(mixed $environment): ?string
    {
        $ceiling = self::ceiling();

        if ($ceiling === null) {
            return 'pac_mode_unsupported';
        }

        if (! self::isValid($environment)) {
            return 'pac_environment_invalid';
        }

        if ($environment === self::LIVE && $ceiling !== self::LIVE) {
            return 'pac_live_not_enabled';
        }

        if ($environment === self::LIVE && ! self::liveAllowedInThisAppEnv()) {
            return 'pac_live_app_env_not_allowed';
        }

        return null;
    }

    /**
     * Ambiente de la empresa (el de su organización). Sin organización (o
     * con el tenant de otra empresa) es Test: el guard falla igual con
     * `pac_organization_missing`. Un valor guardado que no sea válido se
     * devuelve tal cual para que el guard lo rechace.
     */
    public static function forCompany(int $companyId): ?string
    {
        $organization = FacturapiOrganization::query()->where('company_id', $companyId)->first();

        return self::forOrganization($organization);
    }

    public static function forOrganization(?FacturapiOrganization $organization): ?string
    {
        if ($organization === null) {
            return self::TEST;
        }

        $value = $organization->pac_environment;

        return is_string($value) ? $value : null;
    }

    /**
     * Ambiente con el que se opera la factura: el que quedó fijado en su
     * primera reserva o, si todavía no tiene ninguna, el de su empresa (sin
     * escribirlo). La factura con reserva y sin ambiente la bloquea
     * PacEnvironmentAccess.
     */
    public static function forInvoice(Invoice $invoice, ?FacturapiOrganization $organization = null): ?string
    {
        if (filled($invoice->pac_environment)) {
            return $invoice->pac_environment;
        }

        return $organization !== null
            ? self::forOrganization($organization)
            : self::forCompany((int) $invoice->company_id);
    }

    /** Ambiente para mostrar (`test`|`live`), o null si no es válido. */
    public static function display(?string $environment): ?string
    {
        return self::isValid($environment) ? $environment : null;
    }
}
