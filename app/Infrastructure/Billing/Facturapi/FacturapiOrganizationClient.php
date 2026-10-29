<?php

namespace App\Infrastructure\Billing\Facturapi;

use App\Exceptions\Billing\PacAuthenticationException;
use App\Exceptions\Billing\PacRateLimitException;
use App\Exceptions\Billing\PacUnavailableException;
use App\Exceptions\Billing\PacUnexpectedResponseException;
use App\Exceptions\Billing\PacValidationException;
use App\Support\Billing\FacturapiUserKey;
use App\Support\Billing\PacSecretRedactor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Fase 6.27b — Administración de organizaciones con la User Key.
 *
 * Lista cerrada de operaciones (una prueba por reflexión falla si aparece
 * otro método público): consultar, buscar por nombre, crear (sólo la usa
 * el comando del operador), editar datos fiscales, subir el CSD y obtener
 * la llave Test. Nunca renueva llaves (`PUT /apikeys/test` invalidaría la
 * actual), nunca usa llaves Live, nunca borra organizaciones ni el CSD.
 *
 * Sólo se usa en peticiones síncronas de un Admin y en comandos, nunca en
 * jobs. Los mensajes de error pasan por PacSecretRedactor; el cuerpo de la
 * respuesta nunca se guarda ni se registra.
 */
final class FacturapiOrganizationClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly FacturapiUserKey $userKey,
        private readonly int $timeout,
        private readonly int $connectTimeout,
    ) {}

    /** GET /organizations/{id}. Lectura segura de repetir. */
    public function organization(string $organizationId): FacturapiOrganizationSnapshot
    {
        $response = $this->client()->get('/organizations/'.$this->id($organizationId));

        return FacturapiOrganizationSnapshot::fromResponse($this->json($response));
    }

    /**
     * GET /organizations?q= — organizaciones de la cuenta con ese nombre
     * (para avisar de duplicados antes de crear).
     *
     * @return list<FacturapiOrganizationSnapshot>
     */
    public function organizationsNamed(string $name): array
    {
        $data = $this->json($this->client()->get('/organizations', ['q' => $name, 'limit' => 50]));
        $items = is_array($data['data'] ?? null) ? $data['data'] : [];

        return array_values(array_map(
            fn (array $item): FacturapiOrganizationSnapshot => FacturapiOrganizationSnapshot::fromResponse($item),
            array_filter($items, 'is_array'),
        ));
    }

    /** POST /organizations. No es idempotente: nunca se reintenta. */
    public function createOrganization(string $name): FacturapiOrganizationSnapshot
    {
        $response = $this->client(retry: false)->post('/organizations', ['name' => $name]);

        return FacturapiOrganizationSnapshot::fromResponse($this->json($response));
    }

    /**
     * PUT /organizations/{id}/legal (exige User o Live Key). Idempotente:
     * se envían todos los campos para no borrar los que Facturapi ya tiene.
     *
     * @param  array<string, mixed>  $legal
     */
    public function updateLegal(string $organizationId, array $legal): FacturapiOrganizationSnapshot
    {
        $response = $this->client()->put('/organizations/'.$this->id($organizationId).'/legal', $legal);

        return FacturapiOrganizationSnapshot::fromResponse($this->json($response));
    }

    /**
     * PUT /organizations/{id}/certificate (multipart). Reemplaza el CSD y
     * Facturapi toma de él `legal.tax_id`. No se reintenta. La contraseña
     * se borra de cualquier mensaje de error antes de propagarlo.
     */
    public function uploadCertificate(
        string $organizationId,
        string $cer,
        #[SensitiveParameter] string $key,
        #[SensitiveParameter] string $password,
    ): FacturapiOrganizationSnapshot {
        $response = $this->client(retry: false, json: false)
            ->attach('cer', $cer, 'csd.cer')
            ->attach('key', $key, 'csd.key')
            ->put('/organizations/'.$this->id($organizationId).'/certificate', ['password' => $password]);

        return FacturapiOrganizationSnapshot::fromResponse($this->json($response, [$password]));
    }

    /**
     * GET /organizations/{id}/apikeys/test: devuelve la llave Test vigente
     * sin renovarla. El valor nunca aparece en mensajes de error.
     */
    public function testApiKey(string $organizationId): string
    {
        $response = $this->client()->get('/organizations/'.$this->id($organizationId).'/apikeys/test');
        $this->assertSuccessful($response);

        try {
            $key = $response->json();
        } catch (\Throwable) {
            $key = null;
        }

        if (! is_string($key) || ! str_starts_with($key, 'sk_test_')) {
            throw new PacUnexpectedResponseException('La respuesta del PAC (llave Test) no es una llave de ambiente Test.', $response->status());
        }

        return $key;
    }

    private function client(bool $retry = true, bool $json = true): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl)
            ->withToken($this->userKey->value())
            ->acceptJson()
            ->connectTimeout($this->connectTimeout)
            ->timeout($this->timeout);

        if ($json) {
            $request = $request->asJson();
        }

        // Sólo fallos de conexión y sólo en operaciones seguras de repetir;
        // throw:false para que el mapeo de errores sea siempre el propio.
        return $retry
            ? $request->retry(2, 200, fn (\Throwable $exception): bool => $exception instanceof ConnectionException, throw: false)
            : $request;
    }

    /** Un id de organización sólo tiene letras, dígitos, `_` o `-` (nada de rutas ni query). */
    private function id(string $organizationId): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $organizationId) !== 1) {
            throw new InvalidArgumentException('El id de la organización de Facturapi no es válido.');
        }

        return $organizationId;
    }

    /**
     * @param  list<string>  $secrets  Valores que nunca deben aparecer en un mensaje.
     * @return array<string, mixed>
     */
    private function json(Response $response, #[SensitiveParameter] array $secrets = []): array
    {
        $this->assertSuccessful($response, $secrets);

        try {
            $data = $response->json();
        } catch (\Throwable) {
            $data = null;
        }

        if (! is_array($data)) {
            throw new PacUnexpectedResponseException('La respuesta del PAC (organización) no es un objeto JSON.', $response->status());
        }

        return $data;
    }

    /** @param list<string> $secrets */
    private function assertSuccessful(Response $response, #[SensitiveParameter] array $secrets = []): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();

        try {
            $body = $response->json();
        } catch (\Throwable) {
            $body = null;
        }

        $body = is_array($body) ? $body : [];
        $code = isset($body['code']) && is_string($body['code']) ? $body['code'] : null;
        $message = isset($body['message']) && is_string($body['message'])
            ? $body['message']
            : "El PAC respondió con estado HTTP {$status}.";

        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, '[redacted]', $message);
            }
        }

        $message = PacSecretRedactor::redact($message);

        throw match (true) {
            $status === 401 || $status === 403 => new PacAuthenticationException($message, $status, $code),
            $status === 400 || $status === 404 || $status === 422 => new PacValidationException($message, $status, $code),
            $status === 429 => new PacRateLimitException($message, $status, $code),
            $status >= 500 && $status <= 599 => new PacUnavailableException($message, $status, $code),
            default => new PacUnexpectedResponseException($message, $status, $code),
        };
    }
}
