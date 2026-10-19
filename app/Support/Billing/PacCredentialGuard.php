<?php

namespace App\Support\Billing;

use App\Exceptions\Billing\PacConfigurationException;

/**
 * Exige que la llave del PAC corresponda al modo configurado
 * (`billing.pac_mode`) antes de cualquier HTTP. Fase 6.19: sólo el modo
 * `test` está soportado; `live` se rechaza hasta una fase explícita de
 * producción. Nunca expone la llave en mensajes.
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
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config('billing.pac_mode'),
            config('services.facturapi.test_key'),
        );
    }

    /** Código estable del motivo de rechazo, o null si la configuración es válida. */
    public function violation(): ?string
    {
        if (! in_array($this->mode, self::SUPPORTED_MODES, true)) {
            return 'pac_mode_unsupported';
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
