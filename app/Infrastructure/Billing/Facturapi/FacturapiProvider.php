<?php

namespace App\Infrastructure\Billing\Facturapi;

use App\Contracts\Billing\PacProvider;
use App\Data\Billing\PacInvoiceRequest;
use App\Data\Billing\PacInvoiceResult;
use App\Enums\TaxType;
use App\Exceptions\Billing\InvoiceFiscalSnapshotIncompleteException;
use App\Exceptions\Billing\PacAmbiguousInvoiceMatchException;
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
 * Adaptador HTTP hacia Facturapi. Fase 6.1: infraestructura de
 * transporte. Fase 6.2.1: payload/respuesta auditados y corregidos
 * contra el contrato oficial (docs.facturapi.io/api/). Fase 6.2.2:
 * `payment_form`/`payment_method` agregados al payload, y
 * `findInvoiceByExternalId()` agregado usando el endpoint oficial de
 * listado (`GET /invoices?external_id=...`) — ver el reporte de entrega
 * de cada fase para el detalle completo.
 *
 * No importa el SDK oficial de Facturapi (a propósito, ver PacProvider):
 * usa únicamente `Illuminate\Support\Facades\Http`.
 */
class FacturapiProvider implements PacProvider
{
    /**
     * Mapea el catálogo SAT c_Impuesto (`InvoiceItem::tax_code`) al
     * nombre de impuesto que espera `product.taxes[].type` en el
     * contrato oficial de Facturapi (ej. "IVA"), que documenta un
     * nombre, no el código numérico SAT.
     */
    private const TAX_CODE_NAMES = [
        '001' => 'ISR',
        '002' => 'IVA',
        '003' => 'IEPS',
    ];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeout,
        private readonly int $connectTimeout,
    ) {}

    public function name(): string
    {
        return 'facturapi';
    }

    public function createInvoice(PacInvoiceRequest $request): PacInvoiceResult
    {
        $payload = $this->buildInvoicePayload($request);

        $response = $this->client()->post('/invoices', $payload);

        return $this->mapResponse($response);
    }

    public function retrieveInvoice(string $externalId): PacInvoiceResult
    {
        $response = $this->client()->get("/invoices/{$externalId}");

        return $this->mapResponse($response);
    }

    /**
     * Endpoint oficial confirmado (docs.facturapi.io/api/, Fase 6.2.2):
     * `GET /invoices` (relativo a baseUrl, que ya incluye `/v2`) con el
     * query param `external_id` (coincidencia exacta). La respuesta trae
     * paginación (`page`, `total_pages`, `total_results`, `data`) — esa
     * envoltura se normaliza aquí y nunca sale de este adaptador:
     * `findInvoiceByExternalId()` solo devuelve `PacInvoiceResult|null`.
     *
     * El PAC no garantiza unicidad de `external_id`: 0 resultados ->
     * null; exactamente 1 -> se mapea; más de 1 -> se lanza
     * PacAmbiguousInvoiceMatchException en vez de elegir uno en
     * silencio.
     */
    public function findInvoiceByExternalId(string $externalId): ?PacInvoiceResult
    {
        $response = $this->client()->get('/invoices', ['external_id' => $externalId]);

        $this->assertSuccessful($response);

        $body = $response->json();

        if (! is_array($body) || ! isset($body['data']) || ! is_array($body['data'])) {
            throw new PacUnexpectedResponseException(
                'La respuesta de búsqueda del PAC no contiene el campo de listado esperado (data).',
                $response->status(),
            );
        }

        $matches = $body['data'];
        $totalResults = isset($body['total_results']) ? (int) $body['total_results'] : count($matches);

        if ($totalResults === 0 || $matches === []) {
            return null;
        }

        if ($totalResults > 1 || count($matches) > 1) {
            throw new PacAmbiguousInvoiceMatchException($externalId, max($totalResults, count($matches)));
        }

        $only = $matches[0];

        if (! is_array($only)) {
            throw new PacUnexpectedResponseException(
                'La respuesta de búsqueda del PAC no contiene un objeto de factura válido en data[0].',
                $response->status(),
            );
        }

        return $this->mapInvoiceArray($only);
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
     * Estructura auditada contra docs.facturapi.io/api/ en Fase 6.2.1
     * (ver reporte de entrega para el detalle de las diferencias vs. la
     * forma original de Fase 6.1): `use` va al nivel raíz (no dentro de
     * `customer`), `items[]` es `{quantity, discount, product: {...}}`
     * (no un objeto plano), y no existe ningún campo `totals` en el
     * contrato oficial — Facturapi calcula los totales a partir de
     * `items`, así que nunca se envían. `folio`/`series` tampoco se
     * envían deliberadamente: `folio_number` oficial es un entero
     * autogenerado si se omite, y `Invoice::folio` es un identificador
     * interno con formato distinto ("FAC-00000001"), no ese entero.
     *
     * `payment_form` (SAT c_FormaPago, string de 2 caracteres,
     * OBLIGATORIO en el contrato oficial) va al nivel raíz — Fase 6.2.2
     * agregó el snapshot correspondiente en Invoice (`payment_form`,
     * copiado de `Company.default_payment_form` durante la conversión,
     * ver SaleToInvoiceConverter). `assertSnapshotIsComplete()` bloquea
     * si falta o tiene formato inválido, así que si el payload llega a
     * construirse, `payment_form` siempre va presente.
     *
     * `payment_method` (SAT c_MetodoPago, enum "PUE"|"PPD") SÍ está
     * documentado en el mismo endpoint, pero es OPCIONAL — Facturapi
     * aplica su propio default "PUE" si se omite. Por eso solo se
     * incluye cuando el snapshot lo trae (no null); si es null, se omite
     * del payload por completo — nunca se envía un valor inventado
     * localmente, se deja que el default sea el que Facturapi ya
     * documenta y aplica de su lado.
     *
     * @return array<string, mixed>
     */
    private function buildInvoicePayload(PacInvoiceRequest $request): array
    {
        $invoice = $request->invoice;
        $invoice->loadMissing('items');

        $this->assertSnapshotIsComplete($invoice);

        $payload = [
            'customer' => [
                'legal_name' => $invoice->client_name,
                'tax_id' => $invoice->client_rfc,
                'tax_system' => $invoice->client_regimen_fiscal,
                'address' => [
                    'street' => $invoice->client_calle,
                    'exterior' => $invoice->client_no_exterior,
                    'interior' => $invoice->client_no_interior,
                    'neighborhood' => $invoice->client_colonia,
                    'city' => $invoice->client_localidad,
                    'municipality' => $invoice->client_municipio,
                    'zip' => $invoice->client_codigo_postal,
                    'state' => $invoice->client_estado,
                    'country' => $invoice->client_pais,
                ],
            ],
            'items' => $invoice->items->map(fn (InvoiceItem $item): array => [
                'quantity' => (float) $item->quantity,
                'discount' => (float) $item->discount,
                'product' => [
                    'description' => $item->description,
                    'product_key' => $item->product_clave_producto,
                    'price' => (float) $item->unit_price,
                    'unit_key' => $item->product_clave_unidad,
                    'taxability' => $item->product_objeto_imp,
                    'sku' => $item->product_no_identificacion,
                    'taxes' => $item->tax_code !== null ? [[
                        'type' => self::TAX_CODE_NAMES[$item->tax_code],
                        'rate' => (float) $item->tax_rate_value,
                    ]] : [],
                ],
            ])->all(),
            'use' => $invoice->client_uso_cfdi,
            'payment_form' => $invoice->payment_form,
            'currency' => $invoice->currency,
            'external_id' => $request->externalId,
            'idempotency_key' => $request->idempotencyKey,
        ];

        if ($invoice->payment_method !== null) {
            $payload['payment_method'] = $invoice->payment_method;
        }

        return $payload;
    }

    /**
     * Valida, contra columnas de snapshot que SÍ existen en Invoice/
     * InvoiceItem, que ninguna venga vacía (ni con formato inválido)
     * para ESTA factura en particular. Nunca inventa un default fiscal —
     * si algo obligatorio falta, bloquea con una excepción que lista
     * exactamente qué falta, sin llegar a llamar al PAC.
     *
     * `payment_form` (Fase 6.2.2): no existe un catálogo SAT c_FormaPago
     * completo dentro de este proyecto todavía, así que solo se valida
     * formato (exactamente 2 caracteres numéricos) — la validación
     * semántica final (¿es un código real del catálogo?) queda del lado
     * de Facturapi. Si en el futuro el proyecto incorpora ese catálogo,
     * debe validarse aquí contra él en vez de solo el formato.
     */
    private function assertSnapshotIsComplete(Invoice $invoice): void
    {
        $missing = [];

        if (blank($invoice->client_name)) {
            $missing[] = 'client_name (customer.legal_name)';
        }
        if (blank($invoice->client_rfc)) {
            $missing[] = 'client_rfc (customer.tax_id)';
        }
        if (blank($invoice->client_regimen_fiscal)) {
            $missing[] = 'client_regimen_fiscal (customer.tax_system)';
        }
        if (blank($invoice->client_uso_cfdi)) {
            $missing[] = 'client_uso_cfdi (use)';
        }
        if (blank($invoice->client_codigo_postal)) {
            $missing[] = 'client_codigo_postal (customer.address.zip)';
        }

        if (blank($invoice->payment_form)) {
            $missing[] = 'payment_form (SAT c_FormaPago; sin valor en el snapshot — ver Company.default_payment_form)';
        } elseif (! preg_match('/^\d{2}$/', (string) $invoice->payment_form)) {
            $missing[] = "payment_form (formato inválido: '{$invoice->payment_form}', se esperan exactamente 2 dígitos numéricos del catálogo SAT c_FormaPago)";
        }

        if ($invoice->items->isEmpty()) {
            $missing[] = 'items (la factura no tiene líneas)';
        }

        foreach ($invoice->items as $item) {
            $prefix = "items[{$item->id}]";

            if (blank($item->description)) {
                $missing[] = "{$prefix}.description";
            }
            if (blank($item->product_clave_producto)) {
                $missing[] = "{$prefix}.product_clave_producto (product.product_key)";
            }
            if (blank($item->product_clave_unidad)) {
                $missing[] = "{$prefix}.product_clave_unidad (product.unit_key)";
            }
            if (blank($item->product_objeto_imp)) {
                $missing[] = "{$prefix}.product_objeto_imp (product.taxability)";
            }

            if ($item->tax_code !== null && ! array_key_exists((string) $item->tax_code, self::TAX_CODE_NAMES)) {
                $missing[] = "{$prefix}.tax_code (código SAT c_Impuesto no reconocido: '{$item->tax_code}')";
            }

            if ($item->tax_type === TaxType::Retencion) {
                $missing[] = "{$prefix}.tax_type=retencion (el objeto Tax documentado de Facturapi no distingue traslado/retención — ver auditoría Fase 6.2.1)";
            }
        }

        if ($missing !== []) {
            throw new InvoiceFiscalSnapshotIncompleteException($invoice->id, $missing);
        }
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

    /**
     * Mapeo auditado contra docs.facturapi.io/api/ en Fase 6.2.1: la
     * respuesta real de Facturapi NO trae un campo plano `stamped_at`
     * (supuesto sin verificar de Fase 6.1) — la fecha de timbrado vive
     * anidada en `stamp.date`. `id`/`status`/`uuid`/`cancellation_status`
     * sí están confirmados como campos de nivel raíz.
     */
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

        return $this->mapInvoiceArray($data);
    }

    /**
     * Núcleo del mapeo compartido por `mapResponse()` (una sola factura,
     * ej. createInvoice()/retrieveInvoice()) y `findInvoiceByExternalId()`
     * (un elemento de `data[]` en el listado) — mismo shape de objeto
     * Invoice documentado en ambos casos. La fecha de timbrado vive
     * anidada en `stamp.date`, no en un `stamped_at` plano.
     *
     * @param  array<string, mixed>  $data
     */
    private function mapInvoiceArray(array $data): PacInvoiceResult
    {
        $stamp = isset($data['stamp']) && is_array($data['stamp']) ? $data['stamp'] : null;

        return new PacInvoiceResult(
            externalId: (string) $data['id'],
            status: (string) $data['status'],
            uuid: isset($data['uuid']) ? (string) $data['uuid'] : null,
            stampedAt: isset($stamp['date']) ? CarbonImmutable::parse((string) $stamp['date']) : null,
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
