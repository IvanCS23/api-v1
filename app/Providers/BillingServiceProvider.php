<?php

namespace App\Providers;

use App\Contracts\Billing\PacProviderResolver;
use App\Infrastructure\Billing\Facturapi\FacturapiProviderResolver;
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
    }
}
