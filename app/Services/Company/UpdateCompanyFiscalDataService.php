<?php

namespace App\Services\Company;

use App\Enums\FacturapiOrganizationAuditAction;
use App\Exceptions\Company\CompanyFiscalException;
use App\Infrastructure\Billing\Facturapi\FacturapiOrganizationClient;
use App\Models\Company;
use App\Models\FacturapiOrganization;
use App\Models\User;
use App\Services\Billing\FacturapiOrganizationAuditor;
use App\Services\Sat\SatFiscalValidator;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Fase 6.27b — Datos fiscales del emisor, sincronizados primero con
 * Facturapi:
 *
 * 1. Valida localmente (issuerIssues) y la regla del RFC: si la
 *    organización ya tiene RFC (lo toma Facturapi del CSD), el de la
 *    empresa debe ser ese.
 * 2. PUT /organizations/{id}/legal con la User Key, con todos los campos
 *    que tiene la empresa (idempotente).
 * 3. Sólo si Facturapi responde 200 guarda, en una transacción, la
 *    empresa, la copia local de la organización y la auditoría con el
 *    usuario. Si Facturapi falla no se guarda nada.
 *
 * La organización sale siempre de la fila de la empresa, nunca de la
 * petición. El RFC no se envía: Facturapi no lo acepta en /legal.
 */
final class UpdateCompanyFiscalDataService
{
    /** Campos de la empresa que administra esta pantalla. */
    public const FIELDS = [
        'legal_name', 'trade_name', 'rfc', 'fiscal_regime', 'postal_code',
        'street', 'external_number', 'internal_number', 'colony', 'city', 'municipality', 'state',
    ];

    public function __construct(
        private readonly SatFiscalValidator $satValidator,
        private readonly FacturapiOrganizationClient $organizations,
        private readonly FacturapiOrganizationAuditor $auditor,
    ) {}

    /** @param array<string, mixed> $input */
    public function update(User $actor, Company $company, array $input): Company
    {
        $data = $this->normalize($input);
        $issues = $this->satValidator->issuerIssues($data);

        if ($issues !== []) {
            throw new CompanyFiscalException('COMPANY_FISCAL_DATA_INVALID', 'Revisa los datos fiscales de la empresa.', 422, $issues);
        }

        $organization = FacturapiOrganization::query()->where('company_id', $company->id)->first();

        if ($organization === null) {
            throw new CompanyFiscalException('COMPANY_PAC_ORGANIZATION_MISSING', 'La empresa no tiene organización de Facturapi. La crea o la vincula el operador del sistema.', 409);
        }

        if (filled($organization->legal_tax_id) && $data['rfc'] !== SatFiscalValidator::normalizeRfc($organization->legal_tax_id)) {
            throw new CompanyFiscalException('COMPANY_RFC_CSD_MISMATCH', 'El RFC no coincide con el del CSD de la organización.', 422, [[
                'code' => 'COMPANY_RFC_CSD_MISMATCH',
                'field' => 'rfc',
                'message' => "El RFC debe ser el del CSD cargado en Facturapi ({$organization->legal_tax_id}).",
            ]]);
        }

        try {
            $snapshot = $this->organizations->updateLegal($organization->organization_id, $this->legalPayload($data));
        } catch (Throwable $e) {
            throw FacturapiOrganizationErrors::translate($e, $company->id, 'fiscal_update', 'COMPANY_FISCAL_DATA_REJECTED', 'Facturapi rechazó los datos fiscales:');
        }

        $changes = [];

        foreach ($data as $field => $value) {
            $previous = $company->getAttribute($field);

            if ((string) $previous !== (string) $value) {
                $changes[$field] = ['from' => $previous, 'to' => $value];
            }
        }

        DB::transaction(function () use ($actor, $company, $organization, $snapshot, $data, $changes): void {
            $company->forceFill($data)->save();
            $organization->fill($snapshot->toAttributes())->save();
            $this->auditor->record($company->id, $organization->organization_id, FacturapiOrganizationAuditAction::LegalUpdated, $actor, [
                'changes' => $changes,
            ]);
        });

        return $company->refresh();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, ?string>
     */
    private function normalize(array $input): array
    {
        $data = [];

        foreach (self::FIELDS as $field) {
            $value = isset($input[$field]) ? trim((string) $input[$field]) : '';
            $data[$field] = $value === '' ? null : $value;
        }

        $data['rfc'] = SatFiscalValidator::normalizeRfc($data['rfc']);

        return $data;
    }

    /**
     * Campos de `PUT /legal`; los vacíos se omiten.
     *
     * @param  array<string, ?string>  $data
     * @return array<string, mixed>
     */
    private function legalPayload(array $data): array
    {
        $filled = fn (array $values): array => array_filter($values, fn (?string $value): bool => $value !== null);

        return $filled([
            'name' => $data['trade_name'] ?? $data['legal_name'],
            'legal_name' => $data['legal_name'],
            'tax_system' => $data['fiscal_regime'],
        ]) + ['address' => $filled([
            'zip' => $data['postal_code'],
            'street' => $data['street'],
            'exterior' => $data['external_number'],
            'interior' => $data['internal_number'],
            'neighborhood' => $data['colony'],
            'city' => $data['city'],
            'municipality' => $data['municipality'],
            'state' => $data['state'],
        ])];
    }
}
