<?php

namespace App\Services\Billing;

use App\Contracts\Billing\PacProvider;
use App\Data\Billing\PacInvoiceRequest;
use App\Data\Billing\PacInvoiceResult;
use App\Enums\InvoicePacEventType;
use App\Enums\InvoiceStatus;
use App\Events\Billing\InvoiceIssued;
use App\Exceptions\Billing\InvoiceFiscalSnapshotIncompleteException;
use App\Exceptions\Billing\PacAuthenticationException;
use App\Exceptions\Billing\PacException;
use App\Exceptions\Billing\PacRateLimitException;
use App\Exceptions\Billing\PacValidationException;
use App\Exceptions\InvoiceAlreadyIssuedException;
use App\Exceptions\InvoiceCannotBeIssuedException;
use App\Exceptions\InvoiceIssuanceInProgressException;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Support\Billing\PacIdentifiers;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Primera emisión controlada de un CFDI (Fase 6.2, endurecida en Fase
 * 6.2.1 con idempotencia y recuperación ante fallos ambiguos — entorno
 * TEST únicamente). Recibe una Invoice ya creada e Issued, reserva la
 * emisión, la envía al PAC activo mediante PacProvider, y persiste el
 * resultado.
 *
 * No conoce Facturapi ni ningún detalle de un PAC concreto: depende de
 * PacProvider (inyectado), de InvoicePacReadinessService (Fase 6.2.3 —
 * PAC-agnóstico, valida el snapshot antes de contactar al PAC) y de la
 * familia de excepciones PacException (App\Exceptions\Billing\...), que
 * es parte del contrato PAC-agnóstico, no de Facturapi en sí.
 * `pac_provider` se persiste usando `PacProvider::name()` — ya no se
 * deriva por Reflection.
 *
 * Arquitectura del flujo (Fase 6.2.3): IssueInvoiceService → readiness →
 * PacProvider. FacturapiProvider ya no valida completitud del snapshot
 * (eso vive únicamente en InvoicePacReadinessService) — se concentra en
 * traducir el DTO ya validado al contrato externo.
 *
 * Ciclo de vida en tres transacciones cortas, con la llamada HTTP
 * siempre fuera de cualquiera de ellas (nunca se sostiene un lock de
 * fila durante I/O de red):
 *
 *   1. reserve()               — lock corto, valida, marca `pending`,
 *                                 fija/reutiliza `pac_idempotency_key`,
 *                                 incrementa `pac_issue_attempts`, commit.
 *   2. PacProvider::createInvoice() — fuera de transacción.
 *   3a. éxito  -> persiste el resultado, `pac_issue_status=succeeded`,
 *                 despacha InvoiceIssued únicamente tras el commit
 *                 (DB::afterCommit()).
 *   3b. fallo definitivo (validación/autenticación/rate-limit/payload
 *       incompleto) -> `pac_issue_status=failed`, reintentable después
 *       con la MISMA idempotency_key.
 *   3c. fallo ambiguo (timeout/conexión/respuesta no parseable/5xx)
 *       -> `pac_issue_status=reconciliation_required`. Nunca se vuelve a
 *       llamar al PAC automáticamente para esa Invoice — ver
 *       ReconcileInvoiceWithPacService.
 *
 * Si otra llamada encuentra `pending` o `reconciliation_required` al
 * intentar reservar, lanza InvoiceIssuanceInProgressException sin tocar
 * nada ni llamar al PAC — nunca sleeps ni locks manuales en memoria, el
 * único mecanismo de exclusión es el lock de fila de la reserva.
 */
class IssueInvoiceService
{
    private const STATUS_PENDING = 'pending';

    private const STATUS_SUCCEEDED = 'succeeded';

    private const STATUS_FAILED = 'failed';

    private const STATUS_RECONCILIATION_REQUIRED = 'reconciliation_required';

    public function __construct(
        private readonly PacProvider $pacProvider,
        private readonly InvoicePacReadinessService $readiness,
        private readonly InvoicePacAuditService $audit,
    ) {}

    public function issue(Invoice $invoice): Invoice
    {
        $reserved = $this->reserve($invoice);

        $this->audit->appendSafely($reserved, InvoicePacEventType::IssueAttempted, [
            'attempt' => $reserved->pac_issue_attempts,
        ]);

        $pacRequest = new PacInvoiceRequest(
            invoice: $reserved,
            idempotencyKey: $reserved->pac_idempotency_key,
            externalId: PacIdentifiers::externalId($reserved->company_id, $reserved->id),
        );

        $startedAt = microtime(true);

        try {
            // Readiness ANTES de tocar el PAC (arquitectura preferida,
            // Fase 6.2.3): FacturapiProvider ya no valida nada de esto —
            // solo traduce. Si falla, no se hace ninguna llamada HTTP
            // (ver isDefinitiveFailure(): InvoiceFiscalSnapshotIncompleteException
            // sigue siendo un fallo DEFINITIVO, reintentable con la misma
            // idempotency_key una vez corregido el dato).
            $this->readiness->assertReady($reserved);

            $result = $this->pacProvider->createInvoice($pacRequest);
        } catch (Throwable $e) {
            $elapsedMs = $this->elapsedMs($startedAt);
            $failed = $this->recordIssuanceFailure($reserved, $e);
            $this->logAttempt($failed, $elapsedMs, $e);
            $this->audit->appendSafely(
                $failed,
                $failed->pac_issue_status === self::STATUS_FAILED
                    ? InvoicePacEventType::IssueFailed
                    : InvoicePacEventType::ReconciliationRequired,
                [
                    'attempt' => $failed->pac_issue_attempts,
                    'elapsed_ms' => $elapsedMs,
                    'reason' => $e::class,
                ],
                $this->pacCode($e),
            );

            throw $e;
        }

        $elapsedMs = $this->elapsedMs($startedAt);
        $providerSlug = $this->pacProvider->name();

        try {
            $updated = DB::transaction(function () use ($reserved, $result, $providerSlug): Invoice {
                $locked = Invoice::withoutGlobalScope(CompanyScope::class)
                    ->whereKey($reserved->getKey())
                    ->where('company_id', $reserved->company_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Defensa en profundidad: si por cualquier motivo la reserva
                // fue superada (llave distinta a la que acabamos de usar
                // para llamar al PAC), no persistimos un resultado contra
                // una reserva que ya no es la vigente.
                if ($locked->pac_idempotency_key !== $reserved->pac_idempotency_key) {
                    throw new InvoiceIssuanceInProgressException($locked);
                }

                $this->assertNotAlreadyIssued($locked);

                $locked->forceFill([
                    'pac_provider' => $providerSlug,
                    'pac_external_id' => $result->externalId,
                    'cfdi_uuid' => $result->uuid,
                    'pac_status' => $result->status,
                    'stamped_at' => $result->stampedAt,
                    'last_pac_sync_at' => now(),
                    'pac_response' => $result->rawResponse,
                    'pac_last_error' => null,
                    'pac_issue_status' => self::STATUS_SUCCEEDED,
                    'pac_reconciliation_required' => false,
                ])->save();

                DB::afterCommit(function () use ($locked, $result): void {
                    event(new InvoiceIssued($locked, $result));
                });

                return $locked;
            });
        } catch (Throwable $e) {
            // El PAC SÍ respondió con éxito (ya tenemos $result) pero la
            // escritura local falló (ej. violación de índice único,
            // pérdida de conexión a la BD). No es un fallo del PAC — es
            // exactamente la ambigüedad que reconciliation_required
            // modela: puede existir una factura real del lado del PAC sin
            // reflejo local completo. Se marca en una transacción nueva y
            // corta (la que falló ya se revirtió por completo).
            $failed = $this->recordAmbiguousPersistenceFailure($reserved, $result, $e);
            $this->logAttempt($failed, $elapsedMs, $e);
            $this->audit->appendSafely($failed, InvoicePacEventType::ReconciliationRequired, [
                'attempt' => $failed->pac_issue_attempts,
                'elapsed_ms' => $elapsedMs,
                'reason' => 'local_persistence_failed_after_pac_success',
                'pac_external_id_masked' => $this->audit->maskIdentifier($result->externalId),
            ], $this->pacCode($e));

            throw $e;
        }

        $this->logAttempt($updated, $elapsedMs, null);
        $this->audit->appendSafely($updated, InvoicePacEventType::IssueSucceeded, [
            'attempt' => $updated->pac_issue_attempts,
            'elapsed_ms' => $elapsedMs,
            'stamped_at' => $updated->stamped_at,
        ]);

        return $updated;
    }

    /**
     * Transacción corta de reserva: relee bajo lock, valida, y marca
     * `pending` — se cierra (commit) antes de tocar la red. Calcula
     * `pac_idempotency_key` de forma determinista
     * (`erp-invoice:{company_id}:{invoice_id}:v1`); si la Invoice ya
     * tiene una de un intento anterior, la reutiliza tal cual (nunca
     * genera una distinta para la misma Invoice).
     */
    private function reserve(Invoice $invoice): Invoice
    {
        $tenantId = app(CurrentTenant::class)->id();
        $providerSlug = $this->pacProvider->name();

        return DB::transaction(function () use ($invoice, $tenantId, $providerSlug): Invoice {
            $locked = $tenantId !== null
                ? Invoice::withoutGlobalScope(CompanyScope::class)
                    ->whereKey($invoice->getKey())
                    ->where('company_id', $tenantId)
                    ->lockForUpdate()
                    ->first()
                : null;

            if ($locked === null) {
                throw (new ModelNotFoundException)->setModel(Invoice::class, [$invoice->getKey()]);
            }

            $this->assertIssuable($locked);
            $this->assertNotAlreadyIssued($locked);

            if (in_array($locked->pac_issue_status, [self::STATUS_PENDING, self::STATUS_RECONCILIATION_REQUIRED], true)) {
                throw new InvoiceIssuanceInProgressException($locked);
            }

            $idempotencyKey = $locked->pac_idempotency_key
                ?? PacIdentifiers::idempotencyKey($locked->company_id, $locked->id);

            $locked->forceFill([
                'pac_provider' => $providerSlug,
                'pac_idempotency_key' => $idempotencyKey,
                'pac_issue_status' => self::STATUS_PENDING,
                'pac_issue_started_at' => now(),
                'pac_issue_attempts' => $locked->pac_issue_attempts + 1,
            ])->save();

            return $locked;
        });
    }

    /**
     * Persiste el desenlace de un intento fallido en su propia
     * transacción corta. Nunca pisa un resultado ya persistido por otra
     * ejecución (ej. una reconciliación concurrente que ya resolvió el
     * estado) — vuelve a comprobar cfdi_uuid/pac_external_id bajo lock.
     */
    private function recordIssuanceFailure(Invoice $invoice, Throwable $e): Invoice
    {
        $status = $this->isDefinitiveFailure($e) ? self::STATUS_FAILED : self::STATUS_RECONCILIATION_REQUIRED;

        return DB::transaction(function () use ($invoice, $status, $e): Invoice {
            $locked = Invoice::withoutGlobalScope(CompanyScope::class)
                ->whereKey($invoice->getKey())
                ->where('company_id', $invoice->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->cfdi_uuid !== null || $locked->pac_external_id !== null) {
                return $locked;
            }

            $locked->forceFill([
                'pac_issue_status' => $status,
                'pac_last_error' => $this->sanitizeErrorMessage($e),
                'pac_reconciliation_required' => $status === self::STATUS_RECONCILIATION_REQUIRED,
            ])->save();

            return $locked;
        });
    }

    /**
     * El PAC ya respondió con éxito (createInvoice() retornó un
     * PacInvoiceResult válido) pero la transacción que debía persistirlo
     * localmente falló y se revirtió por completo. Siempre
     * `reconciliation_required` — nunca `failed` — porque sabemos con
     * certeza que puede existir una factura real del lado del PAC; un
     * reintento automático con la misma idempotency_key podría no ser
     * seguro sin antes averiguar el estado real (ver
     * ReconcileInvoiceWithPacService).
     */
    private function recordAmbiguousPersistenceFailure(Invoice $invoice, PacInvoiceResult $result, Throwable $e): Invoice
    {
        return DB::transaction(function () use ($invoice, $result, $e): Invoice {
            $locked = Invoice::withoutGlobalScope(CompanyScope::class)
                ->whereKey($invoice->getKey())
                ->where('company_id', $invoice->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->cfdi_uuid !== null || $locked->pac_external_id !== null) {
                return $locked;
            }

            $locked->forceFill([
                'pac_issue_status' => self::STATUS_RECONCILIATION_REQUIRED,
                'pac_reconciliation_required' => true,
                'pac_last_error' => mb_substr(sprintf(
                    'El PAC respondió con éxito (external_id del PAC: %s) pero la persistencia local falló: %s',
                    $result->externalId,
                    $this->sanitizeErrorMessage($e),
                ), 0, 500),
            ])->save();

            return $locked;
        });
    }

    /**
     * Distingue fallos DEFINITIVOS (el PAC respondió con certeza que no
     * creó la factura: payload rechazado, autenticación, rate limit; o
     * ni siquiera se intentó porque el snapshot fiscal está incompleto)
     * de fallos AMBIGUOS (todo lo demás — timeout, conexión interrumpida,
     * 5xx, respuesta no parseable): en esos casos NO se sabe si el PAC
     * llegó a crear la factura, así que nunca se trata como "failed"
     * reintentable a ciegas — se marca `reconciliation_required`.
     */
    private function isDefinitiveFailure(Throwable $e): bool
    {
        return $e instanceof PacValidationException
            || $e instanceof PacAuthenticationException
            || $e instanceof PacRateLimitException
            || $e instanceof InvoiceFiscalSnapshotIncompleteException;
    }

    /**
     * Nunca incluye la API key, cabeceras Authorization/Bearer, ni la
     * respuesta completa del PAC. `PacException::getMessage()`/`pacCode`
     * ya se construyen (en FacturapiProvider) únicamente desde el cuerpo
     * de la respuesta del PAC; para cualquier otra excepción (ej.
     * ConnectionException de Guzzle) se usa igualmente solo el mensaje,
     * nunca la traza. Se trunca de forma defensiva.
     */
    private function sanitizeErrorMessage(Throwable $e): string
    {
        $code = $e instanceof PacException ? ($e->pacCode ?? (string) $e->httpStatus) : null;
        $prefix = $code !== null && $code !== '' ? "[{$code}] " : '';

        return mb_substr($prefix.$e->getMessage(), 0, 500);
    }

    private function logAttempt(Invoice $invoice, int $elapsedMs, ?Throwable $error): void
    {
        Log::info('billing.invoice.pac_issue_attempt', [
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'pac_provider' => $invoice->pac_provider,
            'pac_issue_status' => $invoice->pac_issue_status,
            'pac_external_id' => $invoice->pac_external_id,
            'attempt' => $invoice->pac_issue_attempts,
            'elapsed_ms' => $elapsedMs,
            'pac_error_code' => $error instanceof PacException ? $error->pacCode : null,
        ]);
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function pacCode(Throwable $e): ?string
    {
        return $e instanceof PacException ? ($e->pacCode ?? (string) $e->httpStatus) : null;
    }

    private function assertIssuable(Invoice $invoice): void
    {
        if ($invoice->status !== InvoiceStatus::Issued) {
            throw new InvoiceCannotBeIssuedException($invoice);
        }
    }

    private function assertNotAlreadyIssued(Invoice $invoice): void
    {
        if ($invoice->cfdi_uuid !== null || $invoice->pac_external_id !== null) {
            throw new InvoiceAlreadyIssuedException($invoice);
        }
    }
}
