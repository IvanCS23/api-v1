<?php

namespace App\Infrastructure\Billing\Facturapi;

use App\Contracts\Billing\PacProviderResolver;
use App\Models\FacturapiOrganization;
use App\Models\Invoice;

/**
 * Fase 6.27: FacturapiProvider con la organización y la llave Test de la
 * empresa. FacturapiOrganization usa el scope de tenant, así que con el
 * tenant de otra empresa no se encuentra y el provider queda sin
 * organización: falla cerrado antes de cualquier HTTP.
 */
final class FacturapiProviderResolver implements PacProviderResolver
{
    public function forInvoice(Invoice $invoice): FacturapiProvider
    {
        return $this->forCompany((int) $invoice->company_id);
    }

    public function forCompany(int $companyId): FacturapiProvider
    {
        $organization = FacturapiOrganization::query()->where('company_id', $companyId)->first();

        return new FacturapiProvider(
            baseUrl: (string) config('services.facturapi.base_url'),
            apiKey: $organization?->test_secret_key,
            timeout: (int) config('services.facturapi.timeout'),
            connectTimeout: (int) config('services.facturapi.connect_timeout'),
            mode: config('billing.pac_mode'),
            stampTimezone: config('billing.facturapi.stamp_timezone'),
            organizationId: $organization?->organization_id,
            organizationRequired: true,
        );
    }
}
