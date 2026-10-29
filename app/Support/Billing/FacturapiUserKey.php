<?php

namespace App\Support\Billing;

use App\Exceptions\Billing\PacConfigurationException;
use App\Models\FacturapiOrganization;

/**
 * Fase 6.27b — User Key de Facturapi (`sk_user_`, FACTURAPI_USER_KEY).
 *
 * Es única por cuenta y abre todas sus organizaciones, por eso vive sólo
 * en `.env` (nunca en la BD) y sólo la usa FacturapiOrganizationClient.
 * Sin llave: `pac_user_key_missing`; con otro prefijo:
 * `pac_user_key_invalid`. En ambos casos no se hace ninguna petición.
 */
final class FacturapiUserKey
{
    public const PREFIX = 'sk_user_';

    public function __construct(private readonly ?string $key) {}

    public static function fromConfig(): self
    {
        $key = config('services.facturapi.user_key');

        return new self(is_string($key) ? trim($key) : null);
    }

    public function violation(): ?string
    {
        if (blank($this->key)) {
            return 'pac_user_key_missing';
        }

        if (! str_starts_with((string) $this->key, self::PREFIX)) {
            return 'pac_user_key_invalid';
        }

        return null;
    }

    public function assertConfigured(): void
    {
        if (($violation = $this->violation()) !== null) {
            throw new PacConfigurationException($violation, "La User Key de Facturapi no permite operar ({$violation}); no se contactó al proveedor.");
        }
    }

    /** Sólo para el header Authorization; nunca para mostrar o registrar. */
    public function value(): string
    {
        $this->assertConfigured();

        return (string) $this->key;
    }

    public function hint(): ?string
    {
        return blank($this->key) ? null : FacturapiOrganization::hint((string) $this->key);
    }
}
