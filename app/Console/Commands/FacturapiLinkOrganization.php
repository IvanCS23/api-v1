<?php

namespace App\Console\Commands;

use App\Enums\FacturapiOrganizationAuditAction;
use App\Exceptions\Billing\PacException;
use App\Infrastructure\Billing\Facturapi\FacturapiOrganizationClient;
use App\Infrastructure\Billing\Facturapi\FacturapiOrganizationSnapshot;
use App\Infrastructure\Billing\Facturapi\FacturapiProviderResolver;
use App\Models\Company;
use App\Models\FacturapiOrganization;
use App\Models\Invoice;
use App\Services\Billing\FacturapiOrganizationAuditor;
use App\Support\Billing\FacturapiUserKey;
use App\Support\Billing\PacCredentialGuard;
use App\Support\Billing\StoredPacOrganization;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;

/**
 * Fase 6.27 — Vínculo inicial de una empresa con la organización de
 * Facturapi donde ya timbra, usando la llave Test de `.env`
 * (`services.facturapi.test_key`), único lugar donde esa llave se lee.
 *
 * Sólo lectura contra el PAC: GET /organizations/me. Exige que la
 * organización de la llave sea la esperada (--organization) y muestra
 * cómo se reparten las facturas de la empresa entre organizaciones y en
 * qué difieren los datos fiscales de la empresa de los de Facturapi. Nunca
 * corrige la empresa: eso se hace a mano.
 *
 * Sin --apply no guarda nada. Con --apply crea la fila de
 * facturapi_organizations con la llave cifrada. Nunca sobrescribe un
 * vínculo existente.
 *
 * Fase 6.27b — `--replace-key` reemplaza sólo la llave Test de una
 * empresa ya vinculada, nunca su organización. Origen (`--from`):
 * `user-key` (por omisión: GET /organizations/{id}/apikeys/test con la
 * User Key, sin renovarla; nadie maneja la llave) o `env` (la de `.env`).
 * La llave nueva debe pertenecer a la organización vinculada
 * (GET /organizations/me) antes de guardarse. Queda auditado.
 */
class FacturapiLinkOrganization extends Command
{
    /**
     * @var string
     */
    protected $signature = 'billing:facturapi-link
        {--company= : ID de la empresa}
        {--organization= : ID de la organización de Facturapi esperada}
        {--replace-key : Reemplaza la llave Test de una empresa ya vinculada}
        {--from=user-key : Origen de la llave nueva con --replace-key: user-key o env}
        {--apply : Guarda el vínculo; sin esta opción sólo muestra lo que haría}';

    /**
     * @var string
     */
    protected $description = 'Vincula una empresa con su organización de Facturapi usando la llave Test de .env (GET /organizations/me, solo lectura).';

    private FacturapiProviderResolver $providers;

    private FacturapiOrganizationClient $organizations;

    private FacturapiOrganizationAuditor $auditor;

    public function handle(
        CurrentTenant $tenant,
        FacturapiProviderResolver $providers,
        FacturapiOrganizationClient $organizations,
        FacturapiOrganizationAuditor $auditor,
    ): int {
        // Resueltos al ejecutar, no al registrar el comando: leen la
        // configuración vigente.
        $this->providers = $providers;
        $this->organizations = $organizations;
        $this->auditor = $auditor;

        $companyId = (int) $this->option('company');
        $expected = trim((string) $this->option('organization'));
        $apply = (bool) $this->option('apply');
        $replace = (bool) $this->option('replace-key');

        if ($companyId <= 0 || ($expected === '' && ! $replace)) {
            $this->error($replace ? 'Indica --company.' : 'Indica --company y --organization.');

            return self::FAILURE;
        }

        $company = Company::query()->find($companyId);

        if ($company === null) {
            $this->error("No existe la empresa [{$companyId}].");

            return self::FAILURE;
        }

        if ($replace) {
            $previousTenant = $tenant->id();
            $tenant->set($company->id);

            try {
                return $this->replaceKey($company, $expected, (string) $this->option('from'), $apply);
            } finally {
                $previousTenant === null ? $tenant->clear() : $tenant->set($previousTenant);
            }
        }

        $key = config('services.facturapi.test_key');
        $violation = (new PacCredentialGuard(config('billing.pac_mode'), $key))->violation();

        if ($violation !== null) {
            $this->error("La llave de .env no permite operar ({$violation}); no se contactó al PAC.");

            return self::FAILURE;
        }

        $previousTenant = $tenant->id();
        $tenant->set($company->id);

        try {
            return $this->link($company, $expected, (string) $key, $apply);
        } finally {
            $previousTenant === null ? $tenant->clear() : $tenant->set($previousTenant);
        }
    }

    private function link(Company $company, string $expected, string $key, bool $apply): int
    {
        $this->info(sprintf('Vínculo de la empresa %d (%s) con Facturapi%s', $company->id, $company->trade_name, $apply ? '' : ' — dry-run'));
        $this->line('Llave de .env: '.FacturapiOrganization::hint($key).' (modo '.config('billing.pac_mode').')');

        $current = $company->facturapiOrganization;

        if ($current !== null && $current->organization_id !== $expected) {
            $this->error("La empresa ya está vinculada a la organización {$current->organization_id}; no se sobrescribe.");

            return self::FAILURE;
        }

        $takenBy = DB::table('facturapi_organizations')
            ->where('organization_id', $expected)
            ->where('company_id', '<>', $company->id)
            ->value('company_id');

        if ($takenBy !== null) {
            $this->error("La organización {$expected} ya está vinculada a la empresa {$takenBy}.");

            return self::FAILURE;
        }

        try {
            $snapshot = $this->providers->forKey($key)->currentOrganization();
        } catch (PacException $e) {
            $this->error('No se pudo consultar la organización de la llave: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($snapshot->id !== $expected) {
            $this->error("La llave de .env pertenece a la organización {$snapshot->id}, no a {$expected}. No se guardó nada.");

            return self::FAILURE;
        }

        $this->line("Organización de la llave: {$snapshot->id} (coincide con --organization)");
        $this->invoicesByOrganization($expected);
        $this->fiscalDifferences($company, $snapshot);

        if ($current !== null) {
            $this->info('La empresa ya está vinculada a esta organización. No hay cambios.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->warn('Dry-run: no se guardó nada. Repite con --apply para guardar el vínculo.');

            return self::SUCCESS;
        }

        $organization = new FacturapiOrganization($snapshot->toAttributes());
        $organization->company_id = $company->id;
        $organization->setTestSecretKey($key)->save();
        $this->auditor->record($company->id, $snapshot->id, FacturapiOrganizationAuditAction::TestKeyStored, null, [
            'key_hint' => $organization->test_key_hint,
            'from' => 'env',
        ]);

        $this->info("Vínculo guardado: empresa {$company->id} → organización {$snapshot->id} (llave {$organization->test_key_hint}).");

        return self::SUCCESS;
    }

    private function replaceKey(Company $company, string $expected, string $from, bool $apply): int
    {
        $current = $company->facturapiOrganization;
        $this->info(sprintf('Reemplazo de la llave Test de la empresa %d (%s)%s', $company->id, $company->trade_name, $apply ? '' : ' — dry-run'));

        if (! in_array($from, ['user-key', 'env'], true)) {
            $this->error('--from admite user-key o env.');

            return self::FAILURE;
        }

        if ($current === null) {
            $this->error('La empresa no está vinculada: usa el vínculo inicial (sin --replace-key).');

            return self::FAILURE;
        }

        if ($expected !== '' && $expected !== $current->organization_id) {
            $this->error("La empresa está vinculada a {$current->organization_id}, no a {$expected}. --replace-key nunca cambia de organización.");

            return self::FAILURE;
        }

        $this->line('Organización vinculada: '.$current->organization_id);
        $this->line('Llave actual: '.($current->test_key_hint ?? '(sin llave)'));

        try {
            $key = $from === 'user-key' ? $this->keyFromUserKey($current->organization_id) : $this->keyFromEnv();

            if ($key === null) {
                return self::FAILURE;
            }

            $owner = $this->providers->forKey($key)->currentOrganization();
        } catch (PacException|ConnectionException $e) {
            $this->error('No se pudo obtener o comprobar la llave nueva: '.$e->getMessage());

            return self::FAILURE;
        }

        $newHint = FacturapiOrganization::hint($key);
        $this->line("Llave nueva ({$from}): {$newHint}");

        if ($owner->id !== $current->organization_id) {
            $this->error("La llave nueva pertenece a la organización {$owner->id}, no a la vinculada. No se guardó nada.");

            return self::FAILURE;
        }

        if ($current->test_secret_key !== null && hash_equals($current->test_secret_key, $key)) {
            $this->info('La llave nueva es la misma que la guardada. No hay cambios.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->warn('Dry-run: no se guardó nada. Repite con --apply para reemplazar la llave.');

            return self::SUCCESS;
        }

        $previousHint = $current->test_key_hint;
        $current->fill($owner->toAttributes());
        $current->setTestSecretKey($key)->save();
        $this->auditor->record($company->id, $current->organization_id, FacturapiOrganizationAuditAction::TestKeyReplaced, null, [
            'previous_key_hint' => $previousHint,
            'key_hint' => $current->test_key_hint,
            'from' => $from,
        ]);

        $this->info("Llave reemplazada: {$previousHint} → {$current->test_key_hint}.");

        return self::SUCCESS;
    }

    private function keyFromUserKey(string $organizationId): ?string
    {
        if (($violation = FacturapiUserKey::fromConfig()->violation()) !== null) {
            $this->error("La User Key no permite operar ({$violation}); no se contactó al PAC.");

            return null;
        }

        return $this->organizations->testApiKey($organizationId);
    }

    private function keyFromEnv(): ?string
    {
        $key = config('services.facturapi.test_key');

        if (($violation = (new PacCredentialGuard(config('billing.pac_mode'), $key))->violation()) !== null) {
            $this->error("La llave de .env no permite operar ({$violation}); no se contactó al PAC.");

            return null;
        }

        return (string) $key;
    }

    private function invoicesByOrganization(string $expected): void
    {
        $groups = [];

        Invoice::query()
            ->where(fn ($query) => $query->whereNotNull('pac_external_id')->orWhereNotNull('pac_draft_external_id'))
            ->orderBy('id')
            ->each(function (Invoice $invoice) use (&$groups): void {
                $organization = StoredPacOrganization::conflicts($invoice)
                    ? '(respuestas en conflicto)'
                    : (StoredPacOrganization::of($invoice) ?? '(sin organización guardada)');
                $groups[$organization][] = $invoice->id;
            });

        if ($groups === []) {
            $this->line('La empresa no tiene facturas enviadas al PAC.');

            return;
        }

        $this->line('Facturas de la empresa por organización (según la respuesta guardada):');
        $this->table(['Organización', 'Facturas'], collect($groups)->map(fn (array $ids, string $organization): array => [
            $organization === $expected ? "{$organization} (esta)" : $organization,
            implode(', ', $ids),
        ])->values()->all());
    }

    private function fiscalDifferences(Company $company, FacturapiOrganizationSnapshot $snapshot): void
    {
        $rows = [
            ['Razón social', $company->legal_name, $snapshot->legalName],
            ['RFC', $company->rfc, $snapshot->taxId],
            ['Régimen fiscal', $company->fiscal_regime, $snapshot->taxSystem],
            ['Código postal', $company->postal_code, $snapshot->zip],
        ];

        $this->line('Datos fiscales (empresa frente a Facturapi). No se corrigen aquí:');
        $this->table(['Campo', 'Empresa', 'Facturapi', ''], array_map(fn (array $row): array => [
            $row[0],
            $row[1] ?? '(vacío)',
            $row[2] ?? '(vacío)',
            mb_strtoupper(trim((string) $row[1])) === mb_strtoupper(trim((string) $row[2])) ? 'igual' : 'DIFIERE',
        ], $rows));

        $this->line($snapshot->hasCertificate
            ? sprintf('CSD: cargado, serie %s, vence %s.', $snapshot->certificateSerialNumber ?? '(sin dato)', $snapshot->certificateExpiresAt?->toDateString() ?? '(sin dato)')
            : 'CSD: la organización no tiene certificado cargado.');
    }
}
