<?php

namespace App\Exceptions\Billing;

/**
 * Fase 6.27: la factura pertenece a otra organización de Facturapi (o se
 * archivó por eso) y la llave de su empresa no tiene acceso a ella.
 *
 * Se lanza antes de cualquier HTTP, igual que una configuración inválida,
 * así que hereda su clasificación de fallo definitivo. Evita el 404
 * ambiguo (`invoice_not_found`) que Facturapi devolvería. Los ID de
 * organización no son secretos.
 */
final class PacOrganizationMismatchException extends PacConfigurationException
{
    public const REASON = 'pac_organization_mismatch';

    public function __construct(
        public readonly int $invoiceId,
        public readonly ?string $invoiceOrganizationId,
        public readonly ?string $companyOrganizationId,
        public readonly bool $archived = false,
    ) {
        parent::__construct(self::REASON, $archived
            ? "La factura #{$invoiceId} está archivada: pertenece a la organización de Facturapi {$invoiceOrganizationId}, inaccesible para esta empresa. No se contactó al PAC."
            : "La factura #{$invoiceId} se emitió en otra organización de Facturapi ({$invoiceOrganizationId}); la llave de esta empresa (organización {$companyOrganizationId}) no tiene acceso. No se contactó al PAC.");
    }
}
