<?php

namespace App\Support\Billing;

use App\Exceptions\Billing\PacConfigurationException;
use App\Models\FacturapiOrganization;

/**
 * Exige que la llave del PAC corresponda al modo configurado
 * (`billing.pac_mode`) antes de cualquier HTTP. Fase 6.19: sólo el modo
 * `test` está soportado; `live` se rechaza hasta una fase explícita de
 * producción. Nunca expone la llave en mensajes.
 *
 * Fase 6.27: la llave es la de la organización Facturapi de la empresa,
 * nunca una global. forCompany() exige además que la empresa tenga
 * organización (`pac_organization_missing`). Orden de los motivos: modo,
 * organización, llave ausente, llave de otro modo.
 */
final class PacCredentialGuard
{
    public const MODE_TEST = 'test';

    /** Modos que esta fase permite operar. */
    private const SUPPORTED_MODES = [self::MODE_TEST];

    private const KEY_PREFIXES = [
        'test' => 'sk_test_',
        'live' => 'sk_live_',
    ];

    public function __construct(
        private readonly ?string $mode,
        private readonly ?string $apiKey,
        private readonly bool $organizationRequired = false,
        private readonly ?string $organizationId = null,
    ) {}

    /**
     * Credenciales de la empresa. FacturapiOrganization usa el scope de
     * tenant: con el tenant de otra empresa no se encuentra y el guard
     * falla cerrado con `pac_organization_missing`.
     */
    public static function forCompany(int $companyId): self
    {
        $organization = FacturapiOrganization::query()->where('company_id', $companyId)->first();

        return new self(
            config('billing.pac_mode'),
            $organization?->test_secret_key,
            organizationRequired: true,
            organizationId: $organization?->organization_id,
        );
    }

    /** Código estable del motivo de rechazo, o null si la configuración es válida. */
    public function violation(): ?string
    {
        if (! in_array($this->mode, self::SUPPORTED_MODES, true)) {
            return 'pac_mode_unsupported';
        }

        if ($this->organizationRequired && blank($this->organizationId)) {
            return 'pac_organization_missing';
        }

        if (blank($this->apiKey)) {
            return 'pac_key_missing';
        }

        if (! str_starts_with((string) $this->apiKey, self::KEY_PREFIXES[$this->mode])) {
            return 'pac_key_mode_mismatch';
        }

        return null;
    }

    public function assertConfigured(): void
    {
        $violation = $this->violation();

        if ($violation !== null) {
            throw new PacConfigurationException($violation);
        }
    }
}
