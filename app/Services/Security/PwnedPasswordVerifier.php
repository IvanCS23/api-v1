<?php

namespace App\Services\Security;

use Exception;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Stringable;
use Illuminate\Validation\NotPwnedVerifier;

/**
 * Fase 6.32 (decisión B): verificador de uncompromised() con espera corta y
 * falla abierto.
 *
 * - Consulta api.pwnedpasswords.com con k-anonimato (sólo 5 caracteres del
 *   SHA-1), igual que el de Laravel.
 * - Espera como máximo TIMEOUT_SECONDS (conexión y respuesta), no 30 s.
 * - Si no hay conexión, se vence el tiempo o el servicio responde un error,
 *   la contraseña se ACEPTA (falla abierto) y se registra el evento
 *   EVENT en el log. El registro no lleva la contraseña, ni su hash, ni el
 *   prefijo, ni el mensaje de la excepción (el de Guzzle incluye la URL con
 *   el prefijo). Laravel, en cambio, reportaba la excepción completa.
 */
class PwnedPasswordVerifier extends NotPwnedVerifier
{
    public const TIMEOUT_SECONDS = 3;

    public const EVENT = 'password_breach_check.unavailable';

    /**
     * @param  Factory  $factory
     */
    public function __construct($factory)
    {
        parent::__construct($factory, self::TIMEOUT_SECONDS);
    }

    /**
     * @param  string  $hashPrefix
     * @return Collection<int, string>
     */
    protected function search($hashPrefix)
    {
        try {
            $response = $this->factory
                ->withHeaders(['Add-Padding' => true])
                ->connectTimeout($this->timeout)
                ->timeout($this->timeout)
                ->get('https://api.pwnedpasswords.com/range/'.$hashPrefix);
        } catch (Exception $e) {
            $this->unavailable(['reason' => 'connection', 'exception' => $e::class]);

            return new Collection;
        }

        if (! $response->successful()) {
            $this->unavailable(['reason' => 'http_status', 'status' => $response->status()]);

            return new Collection;
        }

        return (new Stringable($response->body()))->trim()->explode("\n")->filter(
            fn ($line) => str_contains($line, ':')
        );
    }

    /** @param  array<string, mixed>  $context */
    private function unavailable(array $context): void
    {
        Log::warning(self::EVENT, [
            ...$context,
            'timeout_seconds' => $this->timeout,
            'outcome' => 'accepted_fail_open',
        ]);
    }
}
