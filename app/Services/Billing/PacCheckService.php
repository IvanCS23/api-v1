<?php

namespace App\Services\Billing;

use App\Exceptions\Billing\PacException;
use App\Infrastructure\Billing\Facturapi\FacturapiOrganizationSnapshot;
use App\Infrastructure\Billing\Facturapi\FacturapiProviderResolver;
use App\Models\Company;
use App\Models\FacturapiOrganization;
use App\Models\Invoice;
use App\Support\Billing\PacCredentialGuard;
use App\Support\Billing\PacOrganizationAccess;
use App\Support\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;

/**
 * Fase 6.27 — Verificación de credenciales PAC por empresa
 * (billing:pac-check). Sin escrituras: ni la copia local de la
 * organización se actualiza. La única llamada al PAC es
 * GET /organizations/me con la llave de la empresa, y sólo si el guard la
 * acepta.
 *
 * Cada verificación es {code, status, detail} con status ok | warn | fail
 * | info. Nunca incluye la llave, sólo su pista (sk_test_…a1b2).
 *
 * Sin User Key (6.27b) no se verifica que la organización pertenezca a la
 * cuenta de Facturapi.
 */
class PacCheckService
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    public const INFO = 'info';

    /** Días antes del vencimiento del CSD a partir de los cuales se avisa. */
    public const CERTIFICATE_WARNING_DAYS = 30;

    public function __construct(
        private readonly FacturapiProviderResolver $providers,
        private readonly CurrentTenant $tenant,
    ) {}

    /**
     * @param  list<int>  $companyIds
     * @return list<array{id: int, name: string, status: string, checks: list<array{code: string, status: string, detail: string}>}>
     */
    public function check(array $companyIds, CarbonImmutable $now): array
    {
        $previous = $this->tenant->id();
        $results = [];

        try {
            foreach (Company::query()->whereIn('id', $companyIds)->orderBy('id')->get() as $company) {
                $this->tenant->set($company->id);
                $checks = $this->checkCompany($company, $now);
                $results[] = [
                    'id' => $company->id,
                    'name' => $company->trade_name,
                    'status' => $this->worst($checks),
                    'checks' => $checks,
                ];
            }
        } finally {
            $previous === null ? $this->tenant->clear() : $this->tenant->set($previous);
        }

        return $results;
    }

    /** @return list<array{code: string, status: string, detail: string}> */
    private function checkCompany(Company $company, CarbonImmutable $now): array
    {
        $organization = FacturapiOrganization::query()->where('company_id', $company->id)->first();
        $checks = [];

        if ($organization === null) {
            $checks[] = $this->result('organization', self::FAIL, 'La empresa no tiene organización de Facturapi vinculada (billing:facturapi-link).');

            return [...$checks, ...$this->invoiceChecks(null)];
        }

        $checks[] = $this->result('organization', self::OK, "Organización {$organization->organization_id}, llave {$organization->test_key_hint}.");

        $violation = PacCredentialGuard::forCompany($company->id)->violation();

        if ($violation !== null) {
            $checks[] = $this->result('credentials', self::FAIL, "La llave de la organización no permite operar ({$violation}); no se contactó al PAC.");

            return [...$checks, ...$this->invoiceChecks($organization->organization_id)];
        }

        $checks[] = $this->result('credentials', self::OK, 'La llave corresponde al modo '.config('billing.pac_mode').'.');

        try {
            $snapshot = $this->providers->forCompany($company->id)->currentOrganization();
        } catch (PacException $e) {
            $checks[] = $this->result('remote_identity', self::FAIL, 'No se pudo consultar la organización: '.$e->getMessage());

            return [...$checks, ...$this->invoiceChecks($organization->organization_id)];
        }

        if ($snapshot->id !== $organization->organization_id) {
            $checks[] = $this->result('remote_identity', self::FAIL, "La llave pertenece a la organización {$snapshot->id}, no a la vinculada ({$organization->organization_id}).");

            return [...$checks, ...$this->invoiceChecks($organization->organization_id)];
        }

        $checks[] = $this->result('remote_identity', self::OK, 'La llave pertenece a la organización vinculada.');

        return [
            ...$checks,
            ...$this->fiscalChecks($company, $snapshot),
            $this->certificateCheck($snapshot, $now),
            $this->result('pending_steps', self::INFO, $snapshot->pendingSteps === []
                ? 'Sin pasos pendientes para Live.'
                : 'Pendiente para Live (fuera de alcance): '.implode(', ', array_column($snapshot->pendingSteps, 'type')).'.'),
            $this->result('account', self::INFO, 'Sin User Key (6.27b): no se verifica que la organización pertenezca a la cuenta.'),
            ...$this->invoiceChecks($organization->organization_id),
        ];
    }

    /** @return list<array{code: string, status: string, detail: string}> */
    private function fiscalChecks(Company $company, FacturapiOrganizationSnapshot $snapshot): array
    {
        $fields = [
            'fiscal_rfc' => ['RFC', $company->rfc, $snapshot->taxId],
            'fiscal_regime' => ['Régimen fiscal', $company->fiscal_regime, $snapshot->taxSystem],
            'fiscal_postal_code' => ['Código postal', $company->postal_code, $snapshot->zip],
        ];
        $checks = [];

        foreach ($fields as $code => [$label, $local, $remote]) {
            $local = $local === null || trim($local) === '' ? null : mb_strtoupper(trim($local));
            $remote = $remote === null ? null : mb_strtoupper($remote);

            $checks[] = $local !== null && $local === $remote
                ? $this->result($code, self::OK, "{$label}: {$local}.")
                : $this->result($code, self::FAIL, sprintf('%s: empresa %s, Facturapi %s. Corrige la empresa a mano.', $label, $local ?? '(vacío)', $remote ?? '(vacío)'));
        }

        return $checks;
    }

    /**
     * Facturapi timbra en modo test sin CSD, así que ahí su ausencia es un
     * aviso. En cualquier otro modo (live) es un fallo. Pública para poder
     * probar la regla de live, que hoy el guard rechaza antes de llegar aquí.
     *
     * @return array{code: string, status: string, detail: string}
     */
    public function certificateCheck(FacturapiOrganizationSnapshot $snapshot, CarbonImmutable $now): array
    {
        if (! $snapshot->hasCertificate) {
            return config('billing.pac_mode') === PacCredentialGuard::MODE_TEST
                ? $this->result('certificate', self::WARN, 'La organización no tiene CSD cargado. En modo test Facturapi timbra sin él; en live es obligatorio.')
                : $this->result('certificate', self::FAIL, 'La organización no tiene CSD cargado.');
        }

        $serial = $snapshot->certificateSerialNumber ?? '(sin serie)';
        $expires = $snapshot->certificateExpiresAt;

        if ($expires === null) {
            return $this->result('certificate', self::WARN, "CSD {$serial} sin fecha de vencimiento reportada.");
        }

        $days = (int) floor($now->diffInDays($expires, false));

        return match (true) {
            $days < 0 => $this->result('certificate', self::FAIL, "CSD {$serial} vencido el {$expires->toDateString()}."),
            $days < self::CERTIFICATE_WARNING_DAYS => $this->result('certificate', self::WARN, "CSD {$serial} vence el {$expires->toDateString()} ({$days} días)."),
            default => $this->result('certificate', self::OK, "CSD {$serial} vigente hasta el {$expires->toDateString()}."),
        };
    }

    /**
     * Facturas de la empresa según su organización registrada (lectura
     * local, sin HTTP).
     *
     * @return list<array{code: string, status: string, detail: string}>
     */
    private function invoiceChecks(?string $companyOrganization): array
    {
        $counts = [PacOrganizationAccess::OTHER => [], PacOrganizationAccess::ARCHIVED => [], 'missing' => []];

        Invoice::query()
            ->select(['id', 'company_id', 'pac_external_id', 'pac_draft_external_id', 'pac_organization_id', 'pac_organization_archived_at'])
            ->where(fn ($query) => $query->whereNotNull('pac_external_id')->orWhereNotNull('pac_draft_external_id'))
            ->orderBy('id')
            ->each(function (Invoice $invoice) use ($companyOrganization, &$counts): void {
                if ($invoice->pac_organization_archived_at === null && blank($invoice->pac_organization_id)) {
                    $counts['missing'][] = $invoice->id;

                    return;
                }

                $status = PacOrganizationAccess::status($invoice, $companyOrganization);

                if (isset($counts[$status])) {
                    $counts[$status][] = $invoice->id;
                }
            });

        $list = fn (array $ids): string => implode(', ', array_map(fn (int $id): string => "#{$id}", $ids));

        return [
            $counts['missing'] === []
                ? $this->result('invoices_without_organization', self::OK, 'Todas las facturas enviadas al PAC tienen organización registrada.')
                : $this->result('invoices_without_organization', self::WARN, 'Facturas sin organización registrada (billing:backfill-pac-organization): '.$list($counts['missing']).'.'),
            $counts[PacOrganizationAccess::OTHER] === []
                ? $this->result('invoices_other_organization', self::OK, 'Ninguna factura sin archivar pertenece a otra organización.')
                : $this->result('invoices_other_organization', self::FAIL, 'Facturas de otra organización sin archivar (billing:archive-inaccessible-invoices): '.$list($counts[PacOrganizationAccess::OTHER]).'.'),
            $this->result('invoices_archived', self::INFO, $counts[PacOrganizationAccess::ARCHIVED] === []
                ? 'Sin facturas archivadas.'
                : 'Archivadas: '.$list($counts[PacOrganizationAccess::ARCHIVED]).'.'),
        ];
    }

    /** @param list<array{code: string, status: string, detail: string}> $checks */
    private function worst(array $checks): string
    {
        $statuses = array_column($checks, 'status');

        return match (true) {
            in_array(self::FAIL, $statuses, true) => self::FAIL,
            in_array(self::WARN, $statuses, true) => self::WARN,
            default => self::OK,
        };
    }

    /** @return array{code: string, status: string, detail: string} */
    private function result(string $code, string $status, string $detail): array
    {
        return ['code' => $code, 'status' => $status, 'detail' => $detail];
    }
}
