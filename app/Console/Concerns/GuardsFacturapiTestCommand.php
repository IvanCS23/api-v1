<?php

namespace App\Console\Concerns;

use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Support\Billing\PacCredentialGuard;
use App\Support\Tenant\CurrentTenant;

/**
 * Guard común de los comandos billing:facturapi-test-* (Fase 6.19).
 * Lista permitida de entornos (local/testing, nunca production ni
 * staging), modo PAC `test` y llave cuyo prefijo coincide con el modo.
 * Se evalúa antes de cualquier HTTP.
 *
 * Fase 6.27: la llave es la de la organización Facturapi de la empresa de
 * la factura indicada (argumento `invoice`), nunca la de `.env`. Si la
 * factura no existe, el guard la deja pasar y el comando lo reporta.
 */
trait GuardsFacturapiTestCommand
{
    protected function passesFacturapiTestGuard(): bool
    {
        if (app()->environment('production')) {
            return $this->rejectFacturapiTestCommand('Este comando está prohibido en production. Solo se ejecuta en entornos local/testing.');
        }

        if (! app()->environment(['local', 'testing'])) {
            return $this->rejectFacturapiTestCommand('Este comando solo se ejecuta en entornos local/testing.');
        }

        if (config('billing.pac_mode') !== PacCredentialGuard::MODE_TEST) {
            return $this->rejectFacturapiTestCommand('billing.pac_mode debe ser "test" para ejecutar comandos contra Facturapi TEST.');
        }

        $companyId = Invoice::withoutGlobalScope(CompanyScope::class)
            ->whereKey((int) $this->argument('invoice'))
            ->value('company_id');

        if ($companyId === null) {
            return true;
        }

        $violation = $this->companyCredentialViolation((int) $companyId);

        if ($violation === 'pac_organization_missing') {
            return $this->rejectFacturapiTestCommand("La empresa {$companyId} no tiene organización de Facturapi vinculada (billing:facturapi-link); no se contactará al PAC.");
        }

        if ($violation !== null) {
            return $this->rejectFacturapiTestCommand('La llave de la organización de la empresa no corresponde al modo TEST; no se contactará al PAC.');
        }

        return true;
    }

    private function companyCredentialViolation(int $companyId): ?string
    {
        $tenant = app(CurrentTenant::class);
        $previous = $tenant->id();
        $tenant->set($companyId);

        try {
            return PacCredentialGuard::forCompany($companyId)->violation();
        } finally {
            $previous === null ? $tenant->clear() : $tenant->set($previous);
        }
    }

    private function rejectFacturapiTestCommand(string $message): bool
    {
        $this->error($message);

        return false;
    }
}
