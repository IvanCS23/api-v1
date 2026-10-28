<?php

namespace App\Infrastructure\Billing\Facturapi;

use App\Exceptions\Billing\PacUnexpectedResponseException;
use Carbon\CarbonImmutable;

/**
 * Fase 6.27: lo que Facturapi reporta de una organización
 * (GET /organizations/me): identidad, datos fiscales, estado del CSD y
 * pasos pendientes para Live. No contiene ninguna llave.
 *
 * `legal.tax_id` no se edita en Facturapi: lo fija al subir el CSD.
 */
final class FacturapiOrganizationSnapshot
{
    /**
     * @param  list<array{type: string, description: string}>  $pendingSteps
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $legalName,
        public readonly ?string $taxId,
        public readonly ?string $taxSystem,
        public readonly ?string $zip,
        public readonly bool $hasCertificate,
        public readonly ?string $certificateSerialNumber,
        public readonly ?CarbonImmutable $certificateExpiresAt,
        public readonly ?CarbonImmutable $certificateUpdatedAt,
        public readonly bool $isProductionReady,
        public readonly array $pendingSteps,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromResponse(array $data): self
    {
        if (! isset($data['id']) || ! is_string($data['id']) || $data['id'] === '') {
            throw new PacUnexpectedResponseException('La respuesta del PAC (organización) no contiene el campo id.', 200);
        }

        $legal = is_array($data['legal'] ?? null) ? $data['legal'] : [];
        $address = is_array($legal['address'] ?? null) ? $legal['address'] : [];
        $certificate = is_array($data['certificate'] ?? null) ? $data['certificate'] : [];
        $steps = is_array($data['pending_steps'] ?? null) ? $data['pending_steps'] : [];

        return new self(
            id: $data['id'],
            legalName: self::string($legal['legal_name'] ?? null),
            taxId: self::string($legal['tax_id'] ?? null),
            taxSystem: self::string($legal['tax_system'] ?? null),
            zip: self::string($address['zip'] ?? null),
            hasCertificate: ($certificate['has_certificate'] ?? false) === true,
            certificateSerialNumber: self::string($certificate['serial_number'] ?? null),
            certificateExpiresAt: self::date($certificate['expires_at'] ?? null),
            certificateUpdatedAt: self::date($certificate['updated_at'] ?? null),
            isProductionReady: ($data['is_production_ready'] ?? false) === true,
            pendingSteps: array_values(array_map(fn (array $step): array => [
                'type' => (string) ($step['type'] ?? ''),
                'description' => (string) ($step['description'] ?? ''),
            ], array_filter($steps, 'is_array'))),
        );
    }

    /**
     * Atributos de FacturapiOrganization (sin la llave).
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'organization_id' => $this->id,
            'legal_name' => $this->legalName,
            'legal_tax_id' => $this->taxId,
            'legal_tax_system' => $this->taxSystem,
            'legal_zip' => $this->zip,
            'has_certificate' => $this->hasCertificate,
            'certificate_serial_number' => $this->certificateSerialNumber,
            'certificate_expires_at' => $this->certificateExpiresAt,
            'certificate_updated_at' => $this->certificateUpdatedAt,
            'is_production_ready' => $this->isProductionReady,
            'pending_steps' => $this->pendingSteps,
            'snapshot_at' => CarbonImmutable::now(),
        ];
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
