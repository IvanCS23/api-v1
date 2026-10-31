<?php

namespace App\Support\Billing;

use App\Exceptions\Billing\PacConfigurationException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fase 6.29: deja en `laravel.log` cada rechazo del guard PAC
 * (`pac_mode_unsupported`, `pac_organization_missing`, `pac_key_missing`,
 * `pac_key_mode_mismatch`, `pac_organization_mismatch`, …) con la empresa,
 * la factura y la acción, para diagnosticarlo sin reproducirlo. La API sólo
 * responde PAC_CONFIGURATION_INVALID / PAC_ORGANIZATION_MISMATCH.
 *
 * Nunca registra el mensaje de la excepción (puede nombrar organizaciones)
 * ni llaves: sólo el código interno y los identificadores recibidos.
 */
final class PacGuardRejectionLog
{
    /**
     * @param  array{company_id?: ?int, invoice_id?: ?int, action?: ?string, user_id?: ?int}  $context
     */
    public static function record(Throwable $error, array $context): void
    {
        if (! $error instanceof PacConfigurationException) {
            return;
        }

        Log::warning('billing.pac.guard_rejected', [
            'code' => $error->pacCode,
            'exception' => class_basename($error),
            'company_id' => $context['company_id'] ?? null,
            'invoice_id' => $context['invoice_id'] ?? null,
            'action' => $context['action'] ?? null,
            'user_id' => $context['user_id'] ?? null,
        ]);
    }
}
