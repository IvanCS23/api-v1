<?php

namespace App\Contracts\Billing;

use App\Models\Invoice;

/**
 * Fase 6.27: entrega el PacProvider con las credenciales de una empresa
 * (su organización en el PAC), nunca con una llave global.
 *
 * La empresa sale de `invoice->company_id`, no del usuario autenticado:
 * jobs y comandos no tienen usuario. Resolver nunca lanza: si la empresa
 * no tiene organización o llave, el provider devuelto rechaza toda
 * operación antes de cualquier HTTP (PacConfigurationException con
 * `pac_organization_missing`/`pac_key_missing`), igual que una llave
 * inválida en la Fase 6.19, así cada servicio conserva su manejo de
 * fallos definitivos.
 */
interface PacProviderResolver
{
    public function forInvoice(Invoice $invoice): PacProvider;

    public function forCompany(int $companyId): PacProvider;
}
