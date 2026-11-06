<?php

namespace App\Support\Billing;

use App\Exceptions\Billing\PacEnvironmentMismatchException;
use App\Models\FacturapiOrganization;
use App\Models\Invoice;

/**
 * Fase 6.31 — Ambiente de una factura (`invoices.pac_environment`) frente
 * al de su empresa.
 *
 * El ambiente se escribe con la PRIMERA reserva (bind(), bajo el lock de
 * la factura) y nunca cambia. Antes de esa reserva la factura opera con el
 * ambiente de su empresa sin escribirlo: una consulta previa (p. ej. la de
 * folio) que falle no deja la factura atada a ningún ambiente.
 *
 * Estados (null si la factura nunca llegó al PAC y no tiene ambiente):
 * - bound: ambiente fijado e igual al de la empresa; se puede operar.
 * - mismatch: ambiente fijado distinto al de la empresa.
 * - unbound: sin ambiente pero con una reserva o un resultado del PAC
 *   previos (anterior a la 6.31 sin backfill). Se trata como mismatch:
 *   no se sabe con qué llave se creó.
 *
 * Única regla para el resolver, los orquestadores, las capabilities, los
 * jobs, billing:health y billing:pac-check.
 */
final class PacEnvironmentAccess
{
    public const BOUND = 'bound';

    public const MISMATCH = 'mismatch';

    public const UNBOUND = 'unbound';

    /**
     * Columnas que prueban que la factura ya tuvo una reserva o un
     * resultado del PAC.
     */
    public const PAC_STATE_COLUMNS = [
        'pac_draft_idempotency_key',
        'pac_draft_external_id',
        'pac_idempotency_key',
        'pac_external_id',
        'cfdi_uuid',
        'pac_issue_status',
        'pac_cancel_request_status',
        'cfdi_artifacts_status',
        'cancellation_receipt_status',
        'pac_organization_id',
    ];

    public static function hasPacState(Invoice $invoice): bool
    {
        foreach (self::PAC_STATE_COLUMNS as $column) {
            if (filled($invoice->getAttribute($column))) {
                return true;
            }
        }

        return false;
    }

    public static function status(Invoice $invoice, ?string $companyEnvironment): ?string
    {
        if (blank($invoice->pac_environment)) {
            return self::hasPacState($invoice) ? self::UNBOUND : null;
        }

        return $invoice->pac_environment === $companyEnvironment ? self::BOUND : self::MISMATCH;
    }

    public static function isBlocked(?string $status): bool
    {
        return in_array($status, [self::MISMATCH, self::UNBOUND], true);
    }

    public static function statusFor(Invoice $invoice, ?FacturapiOrganization $organization = null): ?string
    {
        $companyEnvironment = $organization !== null
            ? PacEnvironment::forOrganization($organization)
            : PacEnvironment::forCompany((int) $invoice->company_id);

        return self::status($invoice, $companyEnvironment);
    }

    public static function exceptionFor(Invoice $invoice, ?string $companyEnvironment): PacEnvironmentMismatchException
    {
        return new PacEnvironmentMismatchException((int) $invoice->id, $invoice->pac_environment, $companyEnvironment);
    }

    /** Rechaza, antes de cualquier HTTP, operar una factura de otro ambiente. */
    public static function assertAccessible(Invoice $invoice): void
    {
        $companyEnvironment = PacEnvironment::forCompany((int) $invoice->company_id);

        if (self::isBlocked(self::status($invoice, $companyEnvironment))) {
            throw self::exceptionFor($invoice, $companyEnvironment);
        }
    }

    /**
     * Dentro de la transacción de una reserva, con la factura bloqueada:
     * rechaza un ambiente que no corresponde y, si la factura todavía no
     * tiene uno, le asigna el de su empresa (lo guarda el save() de la
     * reserva). La organización se lee con un lock compartido para que
     * billing:pac-environment, que la bloquea, no cambie el ambiente a la
     * mitad.
     */
    public static function bind(Invoice $locked): void
    {
        $organization = FacturapiOrganization::query()
            ->where('company_id', (int) $locked->company_id)
            ->sharedLock()
            ->first();
        $companyEnvironment = PacEnvironment::forOrganization($organization);

        if (self::isBlocked(self::status($locked, $companyEnvironment))) {
            throw self::exceptionFor($locked, $companyEnvironment);
        }

        if (blank($locked->pac_environment)) {
            $locked->forceFill(['pac_environment' => $companyEnvironment]);
        }
    }
}
