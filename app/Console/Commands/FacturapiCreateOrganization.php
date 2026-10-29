<?php

namespace App\Console\Commands;

use App\Enums\FacturapiOrganizationAuditAction;
use App\Exceptions\Billing\PacException;
use App\Infrastructure\Billing\Facturapi\FacturapiOrganizationClient;
use App\Infrastructure\Billing\Facturapi\FacturapiProviderResolver;
use App\Models\Company;
use App\Models\FacturapiOrganization;
use App\Services\Billing\FacturapiOrganizationAuditor;
use App\Support\Billing\FacturapiUserKey;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;

/**
 * Fase 6.27b — Crea la organización de Facturapi de una empresa con la
 * User Key. Sólo comando del operador: no existe endpoint en la API.
 *
 * `POST /organizations` no es idempotente, así que:
 * - el dry-run (y --apply) busca organizaciones de la cuenta con el mismo
 *   nombre y se niega a crear si hay alguna (una respuesta ambigua previa
 *   pudo haberla creado), salvo --allow-duplicate-name;
 * - con --apply se toma un lock por empresa y el id de la organización se
 *   guarda en cuanto Facturapi responde, antes de pedir la llave Test;
 * - si algo falla después del POST, la fila queda sin llave (el guard
 *   responde pac_key_missing) y --resume completa el resto sin volver a
 *   crear la organización.
 *
 * La llave Test se obtiene con GET /apikeys/test (nunca se renueva) y se
 * comprueba con GET /organizations/me antes de guardarla cifrada.
 */
class FacturapiCreateOrganization extends Command
{
    /**
     * @var string
     */
    protected $signature = 'billing:facturapi-create-organization
        {--company= : ID de la empresa}
        {--name= : Nombre de la organización en Facturapi (por omisión, el nombre comercial)}
        {--resume : Completa una organización ya creada que quedó sin llave Test}
        {--allow-duplicate-name : Crea aunque la cuenta ya tenga una organización con ese nombre}
        {--apply : Crea y guarda; sin esta opción sólo muestra lo que haría}';

    /**
     * @var string
     */
    protected $description = 'Crea la organización de Facturapi de una empresa con la User Key (sólo operador).';

    public function handle(
        CurrentTenant $tenant,
        FacturapiOrganizationClient $client,
        FacturapiProviderResolver $providers,
        FacturapiOrganizationAuditor $auditor,
    ): int {
        $companyId = (int) $this->option('company');
        $company = $companyId > 0 ? Company::query()->find($companyId) : null;

        if ($company === null) {
            $this->error($companyId > 0 ? "No existe la empresa [{$companyId}]." : 'Indica --company.');

            return self::FAILURE;
        }

        if (($violation = FacturapiUserKey::fromConfig()->violation()) !== null) {
            $this->error("La User Key no permite operar ({$violation}); no se contactó al PAC.");

            return self::FAILURE;
        }

        $previousTenant = $tenant->id();
        $tenant->set($company->id);

        try {
            $lock = Cache::lock("billing:facturapi-create-organization:{$company->id}", 120);

            if (! $lock->get()) {
                $this->error('Ya hay otra creación en curso para esta empresa.');

                return self::FAILURE;
            }

            try {
                return $this->option('resume')
                    ? $this->resume($company, $client, $providers, $auditor)
                    : $this->create($company, $client, $providers, $auditor);
            } finally {
                $lock->release();
            }
        } finally {
            $previousTenant === null ? $tenant->clear() : $tenant->set($previousTenant);
        }
    }

    private function create(Company $company, FacturapiOrganizationClient $client, FacturapiProviderResolver $providers, FacturapiOrganizationAuditor $auditor): int
    {
        $apply = (bool) $this->option('apply');
        $name = trim((string) ($this->option('name') ?: $company->trade_name));
        $this->info(sprintf('Organización de Facturapi para la empresa %d (%s)%s', $company->id, $company->trade_name, $apply ? '' : ' — dry-run'));

        if ($name === '' || mb_strlen($name) > 100) {
            $this->error('El nombre de la organización es obligatorio y admite hasta 100 caracteres.');

            return self::FAILURE;
        }

        $existing = $company->facturapiOrganization;

        if ($existing !== null) {
            $this->error($existing->test_secret_key === null
                ? "La empresa ya tiene la organización {$existing->organization_id} sin llave Test: usa --resume."
                : "La empresa ya está vinculada a la organización {$existing->organization_id}; no se crea otra.");

            return self::FAILURE;
        }

        try {
            $sameName = $client->organizationsNamed($name);
        } catch (PacException $e) {
            $this->error('No se pudo consultar las organizaciones de la cuenta: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line("Nombre: {$name}");

        if ($sameName !== []) {
            $this->warn('La cuenta ya tiene organizaciones con ese nombre:');
            $this->table(['Organización', 'Razón social', 'RFC'], array_map(fn ($snapshot): array => [
                $snapshot->id, $snapshot->legalName ?? '(vacío)', $snapshot->taxId ?? '(vacío)',
            ], $sameName));

            if (! $this->option('allow-duplicate-name')) {
                $this->error('No se crea otra: puede ser la de un intento anterior. Vincúlala o usa --allow-duplicate-name.');

                return self::FAILURE;
            }
        }

        if (! $apply) {
            $this->warn('Dry-run: no se creó nada. Repite con --apply para crear la organización.');

            return self::SUCCESS;
        }

        try {
            $snapshot = $client->createOrganization($name);
        } catch (ConnectionException) {
            $this->error('Respuesta ambigua de Facturapi: la organización pudo haberse creado. Repite el dry-run para buscarla por nombre antes de intentar otra vez.');

            return self::FAILURE;
        } catch (PacException $e) {
            $this->error('Facturapi no creó la organización: '.$e->getMessage());

            return self::FAILURE;
        }

        // El id se guarda antes de cualquier otro paso: el POST no se repite.
        $organization = new FacturapiOrganization($snapshot->toAttributes());
        $organization->company_id = $company->id;
        $organization->save();
        $auditor->record($company->id, $snapshot->id, FacturapiOrganizationAuditAction::OrganizationCreated, null, ['name' => $name]);
        $this->info("Organización creada: {$snapshot->id}.");

        return $this->storeTestKey($organization, $client, $providers, $auditor);
    }

    private function resume(Company $company, FacturapiOrganizationClient $client, FacturapiProviderResolver $providers, FacturapiOrganizationAuditor $auditor): int
    {
        $organization = $company->facturapiOrganization;

        if ($organization === null || $organization->test_secret_key !== null) {
            $this->error($organization === null
                ? 'La empresa no tiene una organización que completar.'
                : "La organización {$organization->organization_id} ya tiene llave Test; no hay nada que completar.");

            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            $this->warn("Dry-run: se obtendría la llave Test de la organización {$organization->organization_id}. Repite con --apply.");

            return self::SUCCESS;
        }

        return $this->storeTestKey($organization, $client, $providers, $auditor);
    }

    private function storeTestKey(FacturapiOrganization $organization, FacturapiOrganizationClient $client, FacturapiProviderResolver $providers, FacturapiOrganizationAuditor $auditor): int
    {
        $incomplete = "La organización {$organization->organization_id} quedó registrada sin llave Test; repite con --resume --apply.";

        try {
            $key = $client->testApiKey($organization->organization_id);
            $owner = $providers->forKey($key)->currentOrganization();
        } catch (PacException|ConnectionException $e) {
            $this->error('No se pudo obtener o comprobar la llave Test: '.$e->getMessage());
            $this->error($incomplete);

            return self::FAILURE;
        }

        if ($owner->id !== $organization->organization_id) {
            $this->error("La llave Test pertenece a la organización {$owner->id}. No se guardó.");
            $this->error($incomplete);

            return self::FAILURE;
        }

        $organization->fill($owner->toAttributes());
        $organization->setTestSecretKey($key)->save();
        $auditor->record($organization->company_id, $organization->organization_id, FacturapiOrganizationAuditAction::TestKeyStored, null, ['key_hint' => $organization->test_key_hint]);
        $this->info("Llave Test guardada (cifrada): {$organization->test_key_hint}.");

        return self::SUCCESS;
    }
}
