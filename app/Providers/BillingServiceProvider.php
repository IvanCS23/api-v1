<?php

namespace App\Providers;

use App\Contracts\Billing\PacProvider;
use App\Infrastructure\Billing\Facturapi\FacturapiProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Fase 6.1: registra el binding del contrato PAC hacia la implementación
 * concreta de Facturapi. Cambiar de PAC en el futuro es reemplazar este
 * binding por otra clase que implemente PacProvider — nada más del
 * dominio (Invoice, InvoiceWorkflow, SaleToInvoiceConverter) se entera.
 */
class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PacProvider::class, function (): FacturapiProvider {
            return new FacturapiProvider(
                baseUrl: (string) config('services.facturapi.base_url'),
                apiKey: (string) config('services.facturapi.test_key'),
                timeout: (int) config('services.facturapi.timeout'),
                connectTimeout: (int) config('services.facturapi.connect_timeout'),
            );
        });
    }
}
