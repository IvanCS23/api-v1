<?php

namespace App\Services\Billing;

use App\Exceptions\Billing\PacException;
use App\Infrastructure\Billing\Facturapi\FacturapiOrganizationClient;
use App\Infrastructure\Billing\Facturapi\FacturapiOrganizationSnapshot;
use App\Infrastructure\Billing\Facturapi\FacturapiProviderResolver;
use App\Models\Company;
use App\Models\FacturapiOrganization;
use App\Models\Invoice;
use App\Support\Billing\FacturapiUserKey;
use App\Support\Billing\PacCredentialGuard;
use App\Support\Billing\PacEnvironment;
use App\Support\Billing\PacEnvironmentAccess;
use App\Support\Billing\PacOrganizationAccess;
use App\Support\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use InvalidArgumentException;

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
 * Fase 6.27b: con User Key, `account` comprueba que la organización
 * pertenezca a la cuenta (GET /organizations/{id}, también de lectura).
 * Sin User Key es informativo; con otro prefijo, fallo.
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
        private readonly FacturapiOrganizationClient $organizations,
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

        $environment = PacEnvironment::forOrganization($organization);
        $checks[] = $this->result('organization', self::OK, sprintf(
            'Organización %s, ambiente %s, llave Test %s, llave Live %s.',
            $organization->organization_id,
            $environment,
            $organization->test_key_hint ?? '(sin llave)',
            $organization->live_key_hint ?? '(sin llave)',
        ));

        $violation = PacCredentialGuard::forCompany($company->id)->violation();

        if ($violation !== null) {
            $checks[] = $this->result('credentials', self::FAIL, "La llave de la organización no permite operar ({$violation}); no se contactó al PAC.");

            return [...$checks, ...$this->invoiceChecks($organization->organization_id)];
        }

        $checks[] = $this->result('credentials', self::OK, 'La llave corresponde al ambiente '.PacEnvironment::forCompany($company->id).'.');

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
            $this->certificateCheck($snapshot, $now, PacEnvironment::forCompany($company->id)),
            $this->productionReadyCheck($snapshot, $environment),
            $this->supportEmailCheck($company, $snapshot, $environment),
            $this->logoCheck($company, $snapshot),
            $this->accountCheck($organization->organization_id),
            ...$this->invoiceChecks($organization->organization_id),
        ];
    }

    /**
     * La especificación no documenta qué responde Facturapi con una
     * organización de otra cuenta, así que cualquier respuesta distinta de
     * 200 con el mismo id es un fallo. El detalle sólo lleva el estado HTTP
     * y el código, nunca el cuerpo.
     *
     * @return array{code: string, status: string, detail: string}
     */
    private function accountCheck(string $organizationId): array
    {
        $violation = FacturapiUserKey::fromConfig()->violation();

        if ($violation === 'pac_user_key_missing') {
            return $this->result('account', self::INFO, 'Sin User Key: no se verifica que la organización pertenezca a la cuenta.');
        }

        if ($violation !== null) {
            return $this->result('account', self::FAIL, "La User Key no permite operar ({$violation}); no se contactó al PAC.");
        }

        try {
            $snapshot = $this->organizations->organization($organizationId);
        } catch (PacException $e) {
            return $this->result('account', self::FAIL, sprintf(
                'La User Key no tiene acceso a la organización (HTTP %s%s).',
                $e->httpStatus ?? 'sin respuesta',
                $e->pacCode !== null ? ", {$e->pacCode}" : '',
            ));
        } catch (ConnectionException) {
            return $this->result('account', self::FAIL, 'No se pudo contactar a Facturapi con la User Key.');
        } catch (InvalidArgumentException) {
            return $this->result('account', self::FAIL, 'El id de la organización guardada no es válido; no se contactó al PAC.');
        }

        return $snapshot->id === $organizationId
            ? $this->result('account', self::OK, 'La organización pertenece a la cuenta de la User Key.')
            : $this->result('account', self::FAIL, "Facturapi devolvió la organización {$snapshot->id} en lugar de {$organizationId}.");
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
     * aviso. En Live es un fallo. Pública para poder probarla con cualquier
     * ambiente sin pasar por el guard.
     *
     * @return array{code: string, status: string, detail: string}
     */
    public function certificateCheck(FacturapiOrganizationSnapshot $snapshot, CarbonImmutable $now, ?string $environment = PacEnvironment::TEST): array
    {
        if (! $snapshot->hasCertificate) {
            return $environment === PacEnvironment::TEST
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
        $counts = [PacOrganizationAccess::OTHER => [], PacOrganizationAccess::ARCHIVED => [], 'missing' => [], 'environment' => []];
        $companyEnvironment = PacEnvironment::forCompany((int) app(CurrentTenant::class)->id());

        Invoice::query()
            ->select(['id', 'company_id', 'pac_external_id', 'pac_draft_external_id', 'pac_organization_id', 'pac_organization_archived_at', ...PacEnvironmentAccess::PAC_STATE_COLUMNS, 'pac_environment'])
            ->where(fn ($query) => $query->whereNotNull('pac_external_id')->orWhereNotNull('pac_draft_external_id'))
            ->orderBy('id')
            ->each(function (Invoice $invoice) use ($companyOrganization, $companyEnvironment, &$counts): void {
                // Fase 6.31: de otro ambiente, o con operaciones y sin ambiente.
                if (PacEnvironmentAccess::isBlocked(PacEnvironmentAccess::status($invoice, $companyEnvironment))) {
                    $counts['environment'][] = $invoice->id;
                }

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
            $counts['environment'] === []
                ? $this->result('invoices_other_environment', self::OK, 'Ninguna factura enviada al PAC es de otro ambiente.')
                : $this->result('invoices_other_environment', self::FAIL, 'Facturas de otro ambiente o sin ambiente (billing:backfill-pac-environment): '.$list($counts['environment']).'.'),
            $this->result('invoices_archived', self::INFO, $counts[PacOrganizationAccess::ARCHIVED] === []
                ? 'Sin facturas archivadas.'
                : 'Archivadas: '.$list($counts[PacOrganizationAccess::ARCHIVED]).'.'),
        ];
    }

    /**
     * Fase 6.31: en Live exige is_production_ready y ningún paso pendiente;
     * en Test es informativo.
     *
     * @return array{code: string, status: string, detail: string}
     */
    private function productionReadyCheck(FacturapiOrganizationSnapshot $snapshot, ?string $environment): array
    {
        if ($snapshot->isProductionReady && $snapshot->pendingSteps === []) {
            return $this->result('pending_steps', self::OK, 'Facturapi da la organización por lista para producción, sin pasos pendientes.');
        }

        $detail = sprintf(
            'Facturapi: is_production_ready=%s; pasos pendientes: %s.',
            $snapshot->isProductionReady ? 'true' : 'false',
            $snapshot->pendingSteps === [] ? 'ninguno' : implode(', ', array_column($snapshot->pendingSteps, 'type')),
        );

        return $this->result('pending_steps', $environment === PacEnvironment::LIVE ? self::FAIL : self::INFO, $detail);
    }

    /**
     * Fase 6.31: el support_email de Facturapi es el "Responder" de los
     * correos al cliente y debe ser el correo de contacto de la empresa.
     *
     * @return array{code: string, status: string, detail: string}
     */
    private function supportEmailCheck(Company $company, FacturapiOrganizationSnapshot $snapshot, ?string $environment): array
    {
        $email = trim((string) $company->email);

        if ($email !== '' && $snapshot->supportEmail !== null && strcasecmp($snapshot->supportEmail, $email) === 0) {
            return $this->result('support_email', self::OK, 'El correo de respuesta de Facturapi es el de contacto de la empresa.');
        }

        $detail = $email === ''
            ? 'La empresa no tiene correo de contacto; los clientes responderían a otro.'
            : 'El correo de respuesta de Facturapi no es el de contacto de la empresa (guarda de nuevo los Datos fiscales).';

        return $this->result('support_email', $environment === PacEnvironment::LIVE ? self::FAIL : self::WARN, $detail);
    }

    /**
     * Fase 6.31: Facturapi tiene un logotipo que no se subió desde el sistema
     * (en desarrollo, uno heredado). Sólo aviso.
     *
     * @return array{code: string, status: string, detail: string}
     */
    private function logoCheck(Company $company, FacturapiOrganizationSnapshot $snapshot): array
    {
        return $snapshot->hasLogo === true && blank($company->logo)
            ? $this->result('logo', self::WARN, 'La organización tiene un logotipo que no se subió desde Datos fiscales: el PDF lo imprime.')
            : $this->result('logo', self::OK, $snapshot->hasLogo === true ? 'Logotipo subido desde el sistema.' : 'Sin logotipo.');
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
