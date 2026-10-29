<?php

namespace App\Services\Billing;

use App\Enums\FacturapiOrganizationAuditAction;
use App\Models\FacturapiOrganizationAudit;
use App\Models\User;
use App\Support\Billing\PacSecretRedactor;
use LogicException;

/**
 * Fase 6.27b — Escribe la auditoría de la organización de Facturapi.
 *
 * Con usuario es un cambio desde la API (source = api); sin usuario, un
 * comando del operador (source = console). La metadata no puede contener
 * llaves: se rechaza cualquier valor con forma de llave secreta.
 */
final class FacturapiOrganizationAuditor
{
    /** @param array<string, mixed> $metadata */
    public function record(
        int $companyId,
        ?string $organizationId,
        FacturapiOrganizationAuditAction $action,
        ?User $user = null,
        array $metadata = [],
    ): FacturapiOrganizationAudit {
        $encoded = (string) json_encode($metadata, JSON_UNESCAPED_UNICODE);

        if (PacSecretRedactor::redact($encoded) !== $encoded) {
            throw new LogicException('La auditoría de la organización no puede guardar llaves.');
        }

        $audit = new FacturapiOrganizationAudit;
        $audit->forceFill([
            'company_id' => $companyId,
            'organization_id' => $organizationId,
            'user_id' => $user?->id,
            'source' => $user === null ? FacturapiOrganizationAudit::SOURCE_CONSOLE : FacturapiOrganizationAudit::SOURCE_API,
            'action' => $action,
            'metadata' => $metadata,
            'created_at' => now(),
        ])->save();

        return $audit;
    }
}
