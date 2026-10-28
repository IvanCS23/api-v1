<?php

namespace App\Console\Commands;

use App\Exceptions\Billing\PacException;
use App\Infrastructure\Billing\Facturapi\FacturapiOrganizationSnapshot;
use App\Infrastructure\Billing\Facturapi\FacturapiProvider;
use App\Models\Company;
use App\Models\FacturapiOrganization;
use App\Models\Invoice;
use App\Support\Billing\PacCredentialGuard;
use App\Support\Billing\StoredPacOrganization;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Console\Command;
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
 */
class FacturapiLinkOrganization extends Command
{
    /**
     * @var string
     */
    protected $signature = 'billing:facturapi-link
        {--company= : ID de la empresa}
        {--organization= : ID de la organización de Facturapi esperada}
        {--apply : Guarda el vínculo; sin esta opción sólo muestra lo que haría}';

    /**
     * @var string
     */
    protected $description = 'Vincula una empresa con su organización de Facturapi usando la llave Test de .env (GET /organizations/me, solo lectura).';

    public function handle(CurrentTenant $tenant): int
    {
        $companyId = (int) $this->option('company');
        $expected = trim((string) $this->option('organization'));
        $apply = (bool) $this->option('apply');

        if ($companyId <= 0 || $expected === '') {
            $this->error('Indica --company y --organization.');

            return self::FAILURE;
        }

        $company = Company::query()->find($companyId);

        if ($company === null) {
            $this->error("No existe la empresa [{$companyId}].");

            return self::FAILURE;
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
            $snapshot = $this->provider($key)->currentOrganization();
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

        $this->info("Vínculo guardado: empresa {$company->id} → organización {$snapshot->id} (llave {$organization->test_key_hint}).");

        return self::SUCCESS;
    }

    private function provider(string $key): FacturapiProvider
    {
        return new FacturapiProvider(
            baseUrl: (string) config('services.facturapi.base_url'),
            apiKey: $key,
            timeout: (int) config('services.facturapi.timeout'),
            connectTimeout: (int) config('services.facturapi.connect_timeout'),
            mode: config('billing.pac_mode'),
            stampTimezone: config('billing.facturapi.stamp_timezone'),
        );
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
