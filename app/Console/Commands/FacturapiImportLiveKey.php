<?php

namespace App\Console\Commands;

use App\Enums\FacturapiOrganizationAuditAction;
use App\Exceptions\Billing\PacException;
use App\Infrastructure\Billing\Facturapi\FacturapiProviderResolver;
use App\Models\Company;
use App\Models\FacturapiOrganization;
use App\Services\Billing\FacturapiOrganizationAuditor;
use App\Support\Billing\PacCredentialGuard;
use App\Support\Billing\PacEnvironment;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Fase 6.31 — Guarda la llave Live de una empresa ya vinculada.
 *
 * La llave la crea el operador en el panel de Facturapi y se captura aquí
 * con un prompt oculto (`secret(..., false)`: nunca cae a entrada
 * visible). Nunca se recibe por argumento ni por `.env` (config:cache la
 * dejaría copiada en bootstrap/cache) y nunca se imprime: sólo su pista.
 *
 * Antes de guardarla se comprueba con GET /organizations/me, usando la
 * propia llave Live, que pertenece a la organización vinculada. Si
 * Facturapi no la acepta o devuelve otra organización, no se guarda nada.
 * Esa petición pasa por el guard: exige `billing.pac_mode=live` y un
 * APP_ENV permitido (production).
 *
 * No cambia el ambiente de la empresa (eso es billing:pac-environment).
 * Sin --apply sólo comprueba. Queda auditado (live_key_stored /
 * live_key_replaced, sólo con la pista).
 */
class FacturapiImportLiveKey extends Command
{
    /**
     * @var string
     */
    protected $signature = 'billing:facturapi-live-key
        {--company= : ID de la empresa ya vinculada}
        {--apply : Guarda la llave; sin esta opción sólo la comprueba}';

    /**
     * @var string
     */
    protected $description = 'Captura con un prompt oculto la llave Live de una empresa, la comprueba con GET /organizations/me y la guarda cifrada.';

    public function handle(CurrentTenant $tenant, FacturapiProviderResolver $providers, FacturapiOrganizationAuditor $auditor): int
    {
        $companyId = (int) $this->option('company');
        $apply = (bool) $this->option('apply');

        if ($companyId <= 0) {
            $this->error('Indica --company.');

            return self::FAILURE;
        }

        if (! $this->input->isInteractive()) {
            $this->error('La llave Live sólo se captura de forma interactiva, con un prompt oculto. No se guardó nada.');

            return self::FAILURE;
        }

        $company = Company::query()->find($companyId);

        if ($company === null) {
            $this->error("No existe la empresa [{$companyId}].");

            return self::FAILURE;
        }

        $previousTenant = $tenant->id();
        $tenant->set($company->id);

        try {
            $organization = FacturapiOrganization::query()->where('company_id', $company->id)->first();

            if ($organization === null) {
                $this->error('La empresa no está vinculada a una organización de Facturapi (billing:facturapi-link).');

                return self::FAILURE;
            }

            $this->info(sprintf('Llave Live de la empresa %d (%s)%s', $company->id, $company->trade_name, $apply ? '' : ' — dry-run'));
            $this->line('Organización vinculada: '.$organization->organization_id);
            $this->line('Llave Live actual: '.($organization->live_key_hint ?? '(sin llave)'));

            $key = trim((string) $this->secret('Pega la llave Live (sk_live_…); no se mostrará', false));

            return $this->store($company, $organization, $key, $apply, $providers, $auditor);
        } finally {
            $previousTenant === null ? $tenant->clear() : $tenant->set($previousTenant);
        }
    }

    private function store(
        Company $company,
        FacturapiOrganization $organization,
        #[SensitiveParameter] string $key,
        bool $apply,
        FacturapiProviderResolver $providers,
        FacturapiOrganizationAuditor $auditor,
    ): int {
        if (! str_starts_with($key, 'sk_live_')) {
            $this->error('La llave no tiene el prefijo sk_live_. No se guardó nada.');

            return self::FAILURE;
        }

        $violation = (new PacCredentialGuard(PacEnvironment::LIVE, $key))->violation();

        if ($violation !== null) {
            $this->error("No se puede comprobar una llave Live en esta configuración ({$violation}); no se contactó al PAC. No se guardó nada.");

            return self::FAILURE;
        }

        try {
            $owner = $providers->forKey($key, PacEnvironment::LIVE)->currentOrganization();
        } catch (PacException|ConnectionException $e) {
            $this->error('Facturapi no aceptó la llave en GET /organizations/me: '.class_basename($e).'. No se guardó nada.');

            return self::FAILURE;
        }

        if ($owner->id !== $organization->organization_id) {
            $this->error("La llave pertenece a la organización {$owner->id}, no a la vinculada. No se guardó nada.");

            return self::FAILURE;
        }

        $hint = FacturapiOrganization::hint($key);
        $this->line("Llave nueva: {$hint} (pertenece a la organización vinculada)");

        if ($organization->live_secret_key !== null && hash_equals($organization->live_secret_key, $key)) {
            $this->info('La llave es la misma que la guardada. No hay cambios.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->warn('Dry-run: no se guardó nada. Repite con --apply para guardarla.');

            return self::SUCCESS;
        }

        $previousHint = DB::transaction(function () use ($organization, $key): ?string {
            $locked = FacturapiOrganization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $previous = $locked->live_key_hint;
            $locked->setLiveSecretKey($key)->save();

            return $previous;
        });

        $auditor->record(
            $company->id,
            $organization->organization_id,
            $previousHint === null ? FacturapiOrganizationAuditAction::LiveKeyStored : FacturapiOrganizationAuditAction::LiveKeyReplaced,
            null,
            array_filter(['previous_key_hint' => $previousHint, 'key_hint' => $hint]),
        );

        $this->info($previousHint === null ? "Llave Live guardada: {$hint}." : "Llave Live reemplazada: {$previousHint} → {$hint}.");

        if ($previousHint !== null) {
            $this->warn('Revoca la llave anterior en el panel de Facturapi sólo después de comprobar la nueva (la rotación no está verificada).');
        }

        return self::SUCCESS;
    }
}
