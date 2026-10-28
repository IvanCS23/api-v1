<?php

namespace App\Support\Billing;

use App\Exceptions\Billing\PacOrganizationMismatchException;
use App\Models\FacturapiOrganization;
use App\Models\Invoice;

/**
 * Fase 6.27: relación entre la organización de Facturapi de una factura
 * (`pac_organization_id`, la que devolvió el PAC) y la de su empresa.
 *
 * Estados (null si la factura nunca llegó al PAC):
 * - current: misma organización; se puede operar.
 * - other: otra organización; la llave de la empresa no tiene acceso.
 * - archived: cerrada con billing:archive-inaccessible-invoices.
 * - unknown: falta la organización de la factura (antes del backfill) o
 *   de la empresa. No bloquea: el provider verifica la respuesta.
 *
 * Única regla para la API, las capabilities, billing:health y el provider.
 */
final class PacOrganizationAccess
{
    public const CURRENT = 'current';

    public const OTHER = 'other';

    public const ARCHIVED = 'archived';

    public const UNKNOWN = 'unknown';

    public static function status(Invoice $invoice, ?string $companyOrganizationId): ?string
    {
        if ($invoice->pac_organization_archived_at !== null) {
            return self::ARCHIVED;
        }

        if (blank($invoice->pac_external_id) && blank($invoice->pac_draft_external_id) && blank($invoice->pac_organization_id)) {
            return null;
        }

        if (blank($invoice->pac_organization_id) || blank($companyOrganizationId)) {
            return self::UNKNOWN;
        }

        return $invoice->pac_organization_id === $companyOrganizationId ? self::CURRENT : self::OTHER;
    }

    public static function isBlocked(?string $status): bool
    {
        return in_array($status, [self::OTHER, self::ARCHIVED], true);
    }

    /** Organización de la empresa; con el tenant de otra empresa, null. */
    public static function companyOrganizationId(int $companyId): ?string
    {
        return FacturapiOrganization::query()->where('company_id', $companyId)->value('organization_id');
    }

    /** Rechaza, antes de cualquier HTTP, operar una factura de otra organización. */
    public static function assertAccessible(Invoice $invoice): void
    {
        $companyOrganizationId = self::companyOrganizationId((int) $invoice->company_id);
        $status = self::status($invoice, $companyOrganizationId);

        if (self::isBlocked($status)) {
            throw new PacOrganizationMismatchException(
                (int) $invoice->id,
                $invoice->pac_organization_id,
                $companyOrganizationId,
                $status === self::ARCHIVED,
            );
        }
    }
}
