<?php

namespace App\Support\Billing;

use App\Exceptions\Billing\PacConfigurationException;
use App\Models\FacturapiOrganization;
use App\Models\Invoice;
use SensitiveParameter;

/**
 * Exige que la llave del PAC corresponda al ambiente con el que se opera
 * antes de cualquier HTTP. Nunca expone la llave en mensajes.
 *
 * Fase 6.27: la llave es la de la organización Facturapi de la empresa,
 * nunca una global. forCompany() exige además que la empresa tenga
 * organización (`pac_organization_missing`).
 *
 * Fase 6.31: el ambiente es el de la empresa (forCompany) o el de la
 * factura (forInvoice: el fijado en su primera reserva o, antes, el de la
 * empresa) y la llave sale de ese ambiente. PacEnvironment decide si el
 * ambiente puede operar (techo, paro de emergencia, APP_ENV). Orden de los
 * motivos: ambiente, organización, llave ausente, llave de otro ambiente.
 */
final class PacCredentialGuard
{
    /** @deprecated Fase 6.31: usar PacEnvironment::TEST. */
    public const MODE_TEST = PacEnvironment::TEST;

    private const KEY_PREFIXES = [
        PacEnvironment::TEST => 'sk_test_',
        PacEnvironment::LIVE => 'sk_live_',
    ];

    public function __construct(
        private readonly ?string $environment,
        #[SensitiveParameter] private readonly ?string $apiKey,
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

        return self::forEnvironment($organization, PacEnvironment::forOrganization($organization));
    }

    /** Credenciales con las que se opera la factura (Fase 6.31). */
    public static function forInvoice(Invoice $invoice): self
    {
        $organization = FacturapiOrganization::query()->where('company_id', (int) $invoice->company_id)->first();

        return self::forEnvironment($organization, PacEnvironment::forInvoice($invoice, $organization));
    }

    /** La llave de la organización para ese ambiente (null si no hay). */
    public static function keyFor(?FacturapiOrganization $organization, ?string $environment): ?string
    {
        return match ($environment) {
            PacEnvironment::TEST => $organization?->test_secret_key,
            PacEnvironment::LIVE => $organization?->live_secret_key,
            default => null,
        };
    }

    private static function forEnvironment(?FacturapiOrganization $organization, ?string $environment): self
    {
        return new self(
            $environment,
            self::keyFor($organization, $environment),
            organizationRequired: true,
            organizationId: $organization?->organization_id,
        );
    }

    public function environment(): ?string
    {
        return $this->environment;
    }

    /** Código estable del motivo de rechazo, o null si la configuración es válida. */
    public function violation(): ?string
    {
        $environmentViolation = PacEnvironment::violation($this->environment);

        if ($environmentViolation !== null) {
            return $environmentViolation;
        }

        if ($this->organizationRequired && blank($this->organizationId)) {
            return 'pac_organization_missing';
        }

        if (blank($this->apiKey)) {
            return $this->environment === PacEnvironment::LIVE ? 'pac_live_key_missing' : 'pac_key_missing';
        }

        if (! str_starts_with((string) $this->apiKey, self::KEY_PREFIXES[$this->environment])) {
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
