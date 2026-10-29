<?php

namespace App\Infrastructure\Billing\Facturapi;

use App\Contracts\Billing\PacProviderResolver;
use App\Exceptions\Billing\PacOrganizationMismatchException;
use App\Models\FacturapiOrganization;
use App\Models\Invoice;
use App\Support\Billing\PacOrganizationAccess;

/**
 * Fase 6.27: FacturapiProvider con la organización y la llave Test de la
 * empresa. FacturapiOrganization usa el scope de tenant, así que con el
 * tenant de otra empresa no se encuentra y el provider queda sin
 * organización: falla cerrado antes de cualquier HTTP.
 */
final class FacturapiProviderResolver implements PacProviderResolver
{
    /**
     * Si la factura es de otra organización (o está archivada), el provider
     * rechaza toda operación antes del HTTP con
     * PacOrganizationMismatchException; name() sigue respondiendo.
     */
    public function forInvoice(Invoice $invoice): FacturapiProvider
    {
        $organization = $this->organization((int) $invoice->company_id);
        $status = PacOrganizationAccess::status($invoice, $organization?->organization_id);

        return $this->provider($organization, PacOrganizationAccess::isBlocked($status)
            ? new PacOrganizationMismatchException(
                (int) $invoice->id,
                $invoice->pac_organization_id,
                $organization?->organization_id,
                $status === PacOrganizationAccess::ARCHIVED,
            )
            : null);
    }

    public function forCompany(int $companyId): FacturapiProvider
    {
        return $this->provider($this->organization($companyId));
    }

    /**
     * Fase 6.27b: provider con una llave suelta, sin organización exigida.
     * Sólo para que los comandos del operador comprueben a qué organización
     * pertenece una llave (GET /organizations/me) antes de guardarla.
     */
    public function forKey(string $testKey): FacturapiProvider
    {
        return new FacturapiProvider(
            baseUrl: (string) config('services.facturapi.base_url'),
            apiKey: $testKey,
            timeout: (int) config('services.facturapi.timeout'),
            connectTimeout: (int) config('services.facturapi.connect_timeout'),
            mode: config('billing.pac_mode'),
            stampTimezone: config('billing.facturapi.stamp_timezone'),
        );
    }

    private function organization(int $companyId): ?FacturapiOrganization
    {
        return FacturapiOrganization::query()->where('company_id', $companyId)->first();
    }

    private function provider(?FacturapiOrganization $organization, ?PacOrganizationMismatchException $blockedBy = null): FacturapiProvider
    {
        return new FacturapiProvider(
            baseUrl: (string) config('services.facturapi.base_url'),
            apiKey: $organization?->test_secret_key,
            timeout: (int) config('services.facturapi.timeout'),
            connectTimeout: (int) config('services.facturapi.connect_timeout'),
            mode: config('billing.pac_mode'),
            stampTimezone: config('billing.facturapi.stamp_timezone'),
            organizationId: $organization?->organization_id,
            organizationRequired: true,
            blockedBy: $blockedBy,
        );
    }
}
