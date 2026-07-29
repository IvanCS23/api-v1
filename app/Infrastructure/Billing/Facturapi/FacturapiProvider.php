<?php

namespace App\Infrastructure\Billing\Facturapi;

use App\Contracts\Billing\PacProvider;
use App\Data\Billing\PacInvoiceResult;
use App\Exceptions\Billing\PacAuthenticationException;
use App\Exceptions\Billing\PacRateLimitException;
use App\Exceptions\Billing\PacUnavailableException;
use App\Exceptions\Billing\PacUnexpectedResponseException;
use App\Exceptions\Billing\PacValidationException;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Adaptador HTTP hacia Facturapi. Fase 6.1: infraestructura únicamente
 * — ninguna parte del sistema invoca todavía estos métodos desde un
 * flujo real (no hay endpoint público de emisión, no hay webhook, no
 * hay job de timbrado). Solo se ejercita en pruebas bajo `Http::fake()`.
 *
 * No importa el SDK oficial de Facturapi (a propósito, ver PacProvider):
 * usa únicamente `Illuminate\Support\Facades\Http`.
 */
class FacturapiProvider implements PacProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeout,
        private readonly int $connectTimeout,
    ) {}

    public function createInvoice(Invoice $invoice): PacInvoiceResult
    {
        $response = $this->client()->post('/invoices', $this->buildInvoicePayload($invoice));

        return $this->mapResponse($response);
    }

    public function retrieveInvoice(string $externalId): PacInvoiceResult
    {
        $response = $this->client()->get("/invoices/{$externalId}");

        return $this->mapResponse($response);
    }

    public function cancelInvoice(
        string $externalId,
        string $motive,
        ?string $substitutionUuid = null,
    ): PacInvoiceResult {
        $payload = ['motive' => $motive];

        if ($substitutionUuid !== null) {
            $payload['substitution'] = $substitutionUuid;
        }

        $response = $this->client()->delete("/invoices/{$externalId}", $payload);

        return $this->mapResponse($response);
    }

    public function downloadPdf(string $externalId): string
    {
        $response = $this->client()->get("/invoices/{$externalId}/pdf");
        $this->assertSuccessful($response);

        return $response->body();
    }

    public function downloadXml(string $externalId): string
    {
        $response = $this->client()->get("/invoices/{$externalId}/xml");
        $this->assertSuccessful($response);

        return $response->body();
    }

    /**
     * Construye el payload de timbrado únicamente a partir del snapshot
     * ya congelado en la Invoice y sus InvoiceItems — nunca de datos en
     * vivo de `Client`/`Product` (que pudieron cambiar desde que se
     * facturó). No envía nada: el envío ocurre en createInvoice().
     *
     * @return array<string, mixed>
     */
    private function buildInvoicePayload(Invoice $invoice): array
    {
        $invoice->loadMissing('items');

        return [
            'folio' => $invoice->folio,
            'currency' => $invoice->currency,
            'customer' => [
                'legal_name' => $invoice->client_name,
                'tax_id' => $invoice->client_rfc,
                'tax_system' => $invoice->client_regimen_fiscal,
                'use' => $invoice->client_uso_cfdi,
                'address' => [
                    'zip' => $invoice->client_codigo_postal,
                    'street' => $invoice->client_calle,
                    'exterior' => $invoice->client_no_exterior,
                    'interior' => $invoice->client_no_interior,
                    'neighborhood' => $invoice->client_colonia,
                    'locality' => $invoice->client_localidad,
                    'municipality' => $invoice->client_municipio,
                    'state' => $invoice->client_estado,
                    'country' => $invoice->client_pais,
                ],
            ],
            'items' => $invoice->items->map(fn (InvoiceItem $item): array => [
                'description' => $item->description,
                'product_key' => $item->product_clave_producto,
                'unit_key' => $item->product_clave_unidad,
                'no_identification' => $item->product_no_identificacion,
                'tax_object' => $item->product_objeto_imp,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'discount' => (float) $item->discount,
                'taxes' => $item->tax_code !== null ? [[
                    'code' => $item->tax_code,
                    'rate' => (float) $item->tax_rate_value,
                    'type' => $item->tax_type,
                    'factor' => $item->tax_factor_type,
                ]] : [],
            ])->all(),
            'totals' => [
                'subtotal' => (float) $invoice->subtotal,
                'discount' => (float) $invoice->discount_total,
                'tax' => (float) $invoice->tax_total,
                'total' => (float) $invoice->total,
            ],
        ];
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout($this->connectTimeout)
            ->timeout($this->timeout)
            ->retry(2, 200, function (\Throwable $exception): bool {
                // Solo errores transitorios de conexión (timeout, DNS, rechazo
                // de conexión) son seguros de reintentar a ciegas — nunca una
                // respuesta HTTP ya recibida (4xx/5xx), que puede representar
                // un timbrado parcial o una operación no garantizada idempotente.
                return $exception instanceof ConnectionException;
            }, throw: false);
            // throw:false es obligatorio aquí: por defecto, retry() lanza su
            // propia RequestException genérica cuando la respuesta final no
            // es exitosa, sin pasar por assertSuccessful() — eso reemplazaría
            // el mapeo fino a PacValidationException/PacAuthenticationException/
            // etc. por una excepción genérica de Guzzle/Laravel.
    }

    private function mapResponse(Response $response): PacInvoiceResult
    {
        $this->assertSuccessful($response);

        $data = $response->json();

        if (! is_array($data) || ! isset($data['id'], $data['status'])) {
            throw new PacUnexpectedResponseException(
                'La respuesta del PAC no contiene los campos mínimos esperados (id, status).',
                $response->status(),
            );
        }

        return new PacInvoiceResult(
            externalId: (string) $data['id'],
            status: (string) $data['status'],
            uuid: isset($data['uuid']) ? (string) $data['uuid'] : null,
            stampedAt: isset($data['stamped_at']) ? CarbonImmutable::parse($data['stamped_at']) : null,
            cancellationStatus: isset($data['cancellation_status']) ? (string) $data['cancellation_status'] : null,
            rawResponse: $data,
        );
    }

    /**
     * Traduce la respuesta a la excepción correspondiente. El mensaje y
     * `pacCode` solo usan el cuerpo de la respuesta del PAC — nunca la
     * API key ni ninguna cabecera de la petición saliente.
     */
    private function assertSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $body = $this->safeJsonObject($response);
        $code = isset($body['code']) ? (string) $body['code'] : null;
        $message = isset($body['message']) ? (string) $body['message'] : "El PAC respondió con estado HTTP {$status}.";

        throw match (true) {
            $status === 401 || $status === 403 => new PacAuthenticationException($message, $status, $code),
            $status === 400 || $status === 422 => new PacValidationException($message, $status, $code),
            $status === 429 => new PacRateLimitException($message, $status, $code),
            $status >= 500 && $status <= 599 => new PacUnavailableException($message, $status, $code),
            default => new PacUnexpectedResponseException($message, $status, $code),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function safeJsonObject(Response $response): array
    {
        try {
            $json = $response->json();
        } catch (\Throwable) {
            return [];
        }

        return is_array($json) ? $json : [];
    }
}
