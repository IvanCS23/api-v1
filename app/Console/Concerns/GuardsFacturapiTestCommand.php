<?php

namespace App\Console\Concerns;

use App\Support\Billing\PacCredentialGuard;

/**
 * Guard común de los comandos billing:facturapi-test-* (Fase 6.19).
 * Lista permitida de entornos (local/testing, nunca production ni
 * staging), modo PAC `test` y llave cuyo prefijo coincide con el modo.
 * Se evalúa antes de cualquier consulta o HTTP.
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

        if (blank(config('services.facturapi.test_key'))) {
            return $this->rejectFacturapiTestCommand('FACTURAPI_TEST_KEY no está configurada. Configúrala con una llave de TEST antes de continuar.');
        }

        if (PacCredentialGuard::fromConfig()->violation() !== null) {
            return $this->rejectFacturapiTestCommand('La llave configurada no corresponde al modo TEST; no se contactará al PAC.');
        }

        return true;
    }

    private function rejectFacturapiTestCommand(string $message): bool
    {
        $this->error($message);

        return false;
    }
}
