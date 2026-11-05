<?php

namespace App\Console\Commands;

use App\Enums\FacturapiOrganizationAuditAction;
use App\Exceptions\Billing\PacException;
use App\Infrastructure\Billing\Facturapi\FacturapiOrganizationSnapshot;
use App\Infrastructure\Billing\Facturapi\FacturapiProviderResolver;
use App\Models\Company;
use App\Models\FacturapiOrganization;
use App\Services\Billing\FacturapiOrganizationAuditor;
use App\Services\Billing\PacEnvironmentSwitchGuard;
use App\Support\Billing\PacCredentialGuard;
use App\Support\Billing\PacEnvironment;
use App\Support\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;

/**
 * Fase 6.31 — Cambia el ambiente del PAC de una empresa. Sólo por consola:
 * no hay endpoint.
 *
 * A Live exige, con datos remotos frescos (GET /organizations/me con la
 * llave Live, fuera de cualquier lock): la organización vinculada, CSD
 * presente y vigente, is_production_ready=true Y pending_steps vacío, RFC,
 * régimen y C.P. iguales a los de la empresa y el correo de contacto igual
 * al support_email. Además: techo `live`, APP_ENV permitido, llave Live
 * guardada, ninguna factura Test sin terminar (PacEnvironmentSwitchGuard) y
 * ningún job de la empresa en la cola.
 *
 * A Test se niega si la empresa tiene cualquier factura Live (también
 * borradas): quedarían bloqueadas.
 *
 * En ambos sentidos pide escribir el RFC de la empresa y, con --apply,
 * bloquea la organización (lockForUpdate; las reservas leen su ambiente con
 * un lock compartido) y repite las revisiones locales dentro de la
 * transacción antes de escribir. Sin --apply sólo revisa. Queda auditado.
 */
class SwitchPacEnvironment extends Command
{
    /**
     * @var string
     */
    protected $signature = 'billing:pac-environment
        {--company= : ID de la empresa}
        {--set= : Ambiente nuevo: live o test}
        {--apply : Cambia el ambiente; sin esta opción sólo revisa}';

    /**
     * @var string
     */
    protected $description = 'Cambia el ambiente del PAC (test/live) de una empresa tras revisar requisitos con datos remotos frescos.';

    public function handle(
        CurrentTenant $tenant,
        FacturapiProviderResolver $providers,
        PacEnvironmentSwitchGuard $guard,
        FacturapiOrganizationAuditor $auditor,
    ): int {
        $companyId = (int) $this->option('company');
        $target = (string) $this->option('set');
        $apply = (bool) $this->option('apply');

        if ($companyId <= 0 || ! PacEnvironment::isValid($target)) {
            $this->error('Indica --company y --set=live o --set=test.');

            return self::FAILURE;
        }

        if (! $this->input->isInteractive()) {
            $this->error('El cambio de ambiente exige confirmar escribiendo el RFC: no se ejecuta sin interacción.');

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
                $this->error('La empresa no está vinculada a una organización de Facturapi.');

                return self::FAILURE;
            }

            $current = PacEnvironment::forOrganization($organization);
            $this->info(sprintf('Empresa %d (%s): %s → %s%s', $company->id, $company->trade_name, $current, $target, $apply ? '' : ' — revisión (dry-run)'));

            if ($current === $target) {
                $this->info("La empresa ya opera en {$target}. No hay cambios.");

                return self::SUCCESS;
            }

            [$failures, $snapshot] = $target === PacEnvironment::LIVE
                ? $this->liveRequirements($company, $organization, $providers)
                : [[], null];

            $failures = [...$failures, ...$this->localBlockers($company, $target, $guard)];

            if ($failures !== []) {
                $this->error('No se puede cambiar el ambiente:');

                foreach ($failures as $failure) {
                    $this->line(" - {$failure}");
                }

                return self::FAILURE;
            }

            $this->info('Todos los requisitos se cumplen.');

            if (! $apply) {
                $this->warn('Revisión: no se cambió nada. Repite con --apply para cambiar el ambiente.');

                return self::SUCCESS;
            }

            $typed = trim((string) $this->ask("Escribe el RFC de la empresa ({$company->trade_name}) para confirmar el cambio a {$target}"));

            if (strcasecmp($typed, trim((string) $company->rfc)) !== 0) {
                $this->error('El RFC no coincide. No se cambió nada.');

                return self::FAILURE;
            }

            return $this->switch($company, $organization, $target, $snapshot, $guard, $auditor);
        } finally {
            $previousTenant === null ? $tenant->clear() : $tenant->set($previousTenant);
        }
    }

    /**
     * @return array{0: list<string>, 1: ?FacturapiOrganizationSnapshot}
     */
    private function liveRequirements(Company $company, FacturapiOrganization $organization, FacturapiProviderResolver $providers): array
    {
        $failures = [];

        if (PacEnvironment::ceiling() !== PacEnvironment::LIVE) {
            $failures[] = 'billing.pac_mode (BILLING_PAC_MODE) debe ser live.';
        }

        if (! PacEnvironment::liveAllowedInThisAppEnv()) {
            $failures[] = 'APP_ENV ('.app()->environment().') no permite Live; sólo '.implode(', ', (array) config('billing.live_allowed_app_envs')).'.';
        }

        if (blank($organization->live_secret_key)) {
            $failures[] = 'La empresa no tiene llave Live guardada (billing:facturapi-live-key).';
        }

        if ($failures !== []) {
            return [$failures, null];
        }

        $violation = (new PacCredentialGuard(PacEnvironment::LIVE, $organization->live_secret_key))->violation();

        if ($violation !== null) {
            return [["La llave Live guardada no permite operar ({$violation})."], null];
        }

        try {
            $snapshot = $providers->forKey((string) $organization->live_secret_key, PacEnvironment::LIVE)->currentOrganization();
        } catch (PacException|ConnectionException $e) {
            return [['No se pudo consultar la organización con la llave Live ('.class_basename($e).').'], null];
        }

        if ($snapshot->id !== $organization->organization_id) {
            return [["La llave Live pertenece a la organización {$snapshot->id}, no a la vinculada."], null];
        }

        if (! $snapshot->hasCertificate) {
            $failures[] = 'Facturapi no reporta CSD en la organización.';
        } elseif ($snapshot->certificateExpiresAt === null || $snapshot->certificateExpiresAt->lte(CarbonImmutable::now())) {
            $failures[] = 'El CSD de la organización está vencido o sin fecha de vencimiento.';
        }

        if (! $snapshot->isProductionReady) {
            $failures[] = 'Facturapi no da la organización por lista para producción (is_production_ready=false).';
        }

        if ($snapshot->pendingSteps !== []) {
            $failures[] = 'Facturapi reporta pasos pendientes: '.implode(', ', array_column($snapshot->pendingSteps, 'type')).'.';
        }

        foreach (['RFC' => [$company->rfc, $snapshot->taxId], 'Régimen fiscal' => [$company->fiscal_regime, $snapshot->taxSystem], 'Código postal' => [$company->postal_code, $snapshot->zip]] as $label => [$local, $remote]) {
            if (blank($local) || strcasecmp(trim((string) $local), trim((string) $remote)) !== 0) {
                $failures[] = sprintf('%s: empresa %s, Facturapi %s.', $label, blank($local) ? '(vacío)' : $local, $remote ?? '(vacío)');
            }
        }

        $email = trim((string) $company->email);

        if ($email === '') {
            $failures[] = 'La empresa no tiene correo de contacto.';
        } elseif ($snapshot->supportEmail === null || strcasecmp($snapshot->supportEmail, $email) !== 0) {
            $failures[] = 'El support_email de Facturapi no es el correo de contacto de la empresa (guarda de nuevo los Datos fiscales).';
        }

        return [$failures, $snapshot];
    }

    /** @return list<string> */
    private function localBlockers(Company $company, string $target, PacEnvironmentSwitchGuard $guard): array
    {
        $failures = [];

        if ($target === PacEnvironment::TEST && ($live = $guard->liveInvoiceCount()) > 0) {
            $failures[] = "La empresa tiene {$live} factura(s) Live: volver a Test las dejaría bloqueadas.";
        }

        if ($target === PacEnvironment::LIVE) {
            foreach ($guard->unfinishedTestInvoices() as $invoice) {
                $failures[] = sprintf('Factura Test sin terminar #%d (%s): %s.', $invoice['id'], $invoice['folio'], implode(', ', $invoice['states']));
            }
        }

        $jobs = $guard->pendingJobs($company->id);

        if ($jobs === null) {
            $failures[] = 'No se puede revisar la cola con el driver '.config('queue.default').': deja la cola vacía y usa database.';
        } elseif ($jobs > 0) {
            $failures[] = "Hay {$jobs} job(s) de la empresa en la cola (XML/PDF o correo): espera a que terminen.";
        }

        return $failures;
    }

    private function switch(
        Company $company,
        FacturapiOrganization $organization,
        string $target,
        ?FacturapiOrganizationSnapshot $snapshot,
        PacEnvironmentSwitchGuard $guard,
        FacturapiOrganizationAuditor $auditor,
    ): int {
        $result = DB::transaction(function () use ($company, $organization, $target, $snapshot, $guard): array {
            $locked = FacturapiOrganization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $from = PacEnvironment::forOrganization($locked);

            // Con la organización bloqueada, ninguna reserva nueva fija un
            // ambiente: se repiten las revisiones locales antes de escribir.
            $blockers = $this->localBlockers($company, $target, $guard);

            if ($blockers !== []) {
                return ['blockers' => $blockers, 'from' => $from];
            }

            if ($snapshot !== null) {
                $locked->fill($snapshot->toAttributes());
            }

            $locked->forceFill(['pac_environment' => $target])->save();

            return ['blockers' => [], 'from' => $from];
        });

        if ($result['blockers'] !== []) {
            $this->error('Cambió algo durante la revisión; no se cambió el ambiente:');

            foreach ($result['blockers'] as $blocker) {
                $this->line(" - {$blocker}");
            }

            return self::FAILURE;
        }

        $auditor->record($company->id, $organization->organization_id, FacturapiOrganizationAuditAction::EnvironmentChanged, null, [
            'from' => $result['from'],
            'to' => $target,
        ]);

        $this->info("Ambiente cambiado: {$result['from']} → {$target}.");

        return self::SUCCESS;
    }
}
