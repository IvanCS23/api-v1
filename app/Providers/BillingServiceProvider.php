<?php

namespace App\Providers;

use App\Contracts\Billing\PacProviderResolver;
use App\Infrastructure\Billing\Facturapi\FacturapiOrganizationClient;
use App\Infrastructure\Billing\Facturapi\FacturapiProviderResolver;
use App\Support\Billing\FacturapiUserKey;
use Illuminate\Support\ServiceProvider;

/**
 * Fase 6.1: registra el binding del contrato PAC hacia la implementación
 * concreta de Facturapi. Cambiar de PAC en el futuro es reemplazar este
 * binding por otra clase que implemente el contrato — nada más del
 * dominio (Invoice, InvoiceWorkflow, SaleToInvoiceConverter) se entera.
 *
 * Fase 6.27: ya no hay un PacProvider global con la llave de `.env`. Los
 * servicios piden el provider de la empresa de cada factura al
 * PacProviderResolver.
 */
class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PacProviderResolver::class, FacturapiProviderResolver::class);

        // Fase 6.27b: la configuración se lee al resolver, no al registrar.
        $this->app->bind(FacturapiOrganizationClient::class, fn (): FacturapiOrganizationClient => new FacturapiOrganizationClient(
            baseUrl: (string) config('services.facturapi.base_url'),
            userKey: FacturapiUserKey::fromConfig(),
            timeout: (int) config('services.facturapi.timeout'),
            connectTimeout: (int) config('services.facturapi.connect_timeout'),
        ));
    }
}
