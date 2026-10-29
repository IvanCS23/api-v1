<?php

namespace App\Http\Resources;

use App\Models\Company;
use App\Models\FacturapiOrganization;
use App\Services\Sat\SatFiscalValidator;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fase 6.27b — Datos fiscales de la empresa y estado de su organización
 * de Facturapi (copia local). Nunca incluye la llave, su pista ni el id de
 * la organización: la pantalla no los necesita.
 *
 * @mixin Company
 */
class CompanyFiscalResource extends JsonResource
{
    public function __construct(Company $company, private readonly ?FacturapiOrganization $organization)
    {
        parent::__construct($company);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $validator = app(SatFiscalValidator::class);
        $organization = $this->organization;
        $expiresAt = $organization?->certificate_expires_at;

        return [
            'legal_name' => $this->legal_name,
            'trade_name' => $this->trade_name,
            'rfc' => $this->rfc,
            'fiscal_regime' => $this->fiscal_regime,
            'postal_code' => $this->postal_code,
            'address' => [
                'street' => $this->street,
                'external_number' => $this->external_number,
                'internal_number' => $this->internal_number,
                'colony' => $this->colony,
                'city' => $this->city,
                'municipality' => $this->municipality,
                'state' => $this->state,
            ],
            'issues' => $validator->issuerIssues($this->resource->only(['rfc', 'legal_name', 'fiscal_regime', 'postal_code'])),
            'can_edit' => (bool) $request->user('api')?->can('updateFiscal', $this->resource),
            'organization' => $organization === null ? null : [
                'legal_name' => $organization->legal_name,
                'legal_tax_id' => $organization->legal_tax_id,
                'legal_tax_system' => $organization->legal_tax_system,
                'legal_zip' => $organization->legal_zip,
                'rfc_matches' => $organization->legal_tax_id === null
                    ? null
                    : SatFiscalValidator::normalizeRfc($this->rfc) === SatFiscalValidator::normalizeRfc($organization->legal_tax_id),
                'snapshot_at' => $organization->snapshot_at?->toIso8601String(),
                'certificate' => [
                    'has_certificate' => (bool) $organization->has_certificate,
                    'serial_number' => $organization->certificate_serial_number,
                    'expires_at' => $expiresAt?->toIso8601String(),
                    'updated_at' => $organization->certificate_updated_at?->toIso8601String(),
                    'days_left' => $expiresAt === null ? null : (int) floor(CarbonImmutable::now()->diffInDays($expiresAt, false)),
                ],
            ],
        ];
    }
}
