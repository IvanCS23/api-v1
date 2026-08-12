<?php

namespace App\Services\Billing;

use App\Contracts\Billing\PacProvider;
use App\Data\Billing\PacInvoiceResult;
use App\Enums\CfdiCancellationMotive;
use App\Enums\InvoicePacEventType;
use App\Exceptions\Billing\PacAuthenticationException;
use App\Exceptions\Billing\PacException;
use App\Exceptions\Billing\PacRateLimitException;
use App\Exceptions\Billing\PacUnexpectedResponseException;
use App\Exceptions\Billing\PacValidationException;
use App\Exceptions\InvoiceCannotBeCancelledException;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Solicita cancelación fiscal de un CFDI existente. Nunca modifica el estado
 * interno de Invoice, emite/timbra recursos ni elimina/descarga artifacts.
 */
class CancelInvoiceWithPacService
{
    private const CANCELLABLE_STATUSES = [null, 'none', 'rejected', 'expired'];

    public function __construct(
        private readonly PacProvider $pacProvider,
        private readonly InvoicePacAuditService $audit,
    ) {}

    public function cancel(
        Invoice $invoice,
        CfdiCancellationMotive $motive,
        ?string $substitutionUuid = null,
    ): Invoice {
        $tenantId = app(CurrentTenant::class)->id();
        $current = $this->requireCurrentTenantInvoice($invoice, $tenantId);
        $substitutionUuid = $this->validatedSubstitutionUuid($current, $motive, $substitutionUuid);

        $this->assertCancellable($current);

        $expectedExternalId = (string) $current->pac_external_id;
        $expectedUuid = (string) $current->cfdi_uuid;
        $initialPacStatus = $current->pac_status;
        $initialCancellationStatus = $current->cancellation_status;
        $startedAt = microtime(true);

        $this->audit->appendSafely($current, InvoicePacEventType::CancellationRequested, array_filter([
            'motive' => $motive->value,
            'substitution_uuid_hash' => $substitutionUuid !== null ? hash('sha256', $substitutionUuid) : null,
        ], static fn (mixed $value): bool => $value !== null));

        try {
            $result = $this->pacProvider->cancelInvoice(
                $expectedExternalId,
                $motive->value,
                $substitutionUuid,
            );
        } catch (Throwable $e) {
            $this->recordFailure($current, $tenantId, $e, $this->isAmbiguous($e));
            $this->logResult($current, $motive, null, $startedAt, $e);

            if ($this->isAmbiguous($e)) {
                $this->auditReconciliationRequired($current, $motive, $e, $startedAt);
            }

            throw $e;
        }

        if ($exception = $this->identityException($current, $result)) {
            $this->recordFailure($current, $tenantId, $exception, true);
            $this->logResult($current, $motive, null, $startedAt, $exception);
            $this->auditReconciliationRequired($current, $motive, $exception, $startedAt);

            throw $exception;
        }

        try {
            $updated = DB::transaction(function () use (
                $current,
                $tenantId,
                $expectedExternalId,
                $expectedUuid,
                $initialPacStatus,
                $initialCancellationStatus,
                $result,
            ): Invoice {
                if (app(CurrentTenant::class)->id() !== $tenantId) {
                    throw (new ModelNotFoundException)->setModel(Invoice::class, [$current->getKey()]);
                }

                $locked = Invoice::withoutGlobalScope(CompanyScope::class)
                    ->whereKey($current->getKey())
                    ->where('company_id', $tenantId)
                    ->lockForUpdate()
                    ->firstOrFail();

                // La identidad o el estado cambió mientras el PAC respondía.
                // Se descarta la respuesta vieja y se conserva al ganador.
                if ($locked->pac_external_id !== $expectedExternalId
                    || strcasecmp((string) $locked->cfdi_uuid, $expectedUuid) !== 0
                    || $locked->pac_status !== $initialPacStatus
                    || $locked->cancellation_status !== $initialCancellationStatus) {
                    return $locked;
                }

                if ($exception = $this->identityException($locked, $result)) {
                    $locked->forceFill([
                        'pac_reconciliation_required' => true,
                        'pac_last_error' => $this->sanitizeErrorMessage($exception),
                    ])->save();

                    throw $exception;
                }

                $locked->forceFill([
                    'pac_status' => $result->status,
                    'cancellation_status' => $result->cancellationStatus ?? $locked->cancellation_status,
                    'last_pac_sync_at' => now(),
                    'pac_response' => $result->rawResponse,
                    'pac_last_error' => null,
                    'pac_reconciliation_required' => $this->requiresReconciliation($result),
                ])->save();

                return $locked;
            });
        } catch (Throwable $e) {
            $this->recordFailure($current, $tenantId, $e, true);
            $this->logResult($current, $motive, null, $startedAt, $e);
            $this->auditReconciliationRequired($current, $motive, $e, $startedAt);

            throw $e;
        }

        $this->logResult($updated, $motive, $result, $startedAt, null);

        $this->audit->appendSafely(
            $updated,
            $this->cancellationEventType($result),
            [
                'motive' => $motive->value,
                'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'result_status' => $result->status,
                'cancellation_status' => $result->cancellationStatus,
            ],
        );

        return $updated;
    }

    private function assertCancellable(Invoice $invoice): void
    {
        if ($invoice->pac_external_id === null) {
            throw new InvoiceCannotBeCancelledException($invoice, 'falta pac_external_id');
        }

        if ($invoice->cfdi_uuid === null) {
            throw new InvoiceCannotBeCancelledException($invoice, 'falta cfdi_uuid');
        }

        if ($invoice->pac_status !== 'valid') {
            throw new InvoiceCannotBeCancelledException($invoice, 'pac_status debe ser valid');
        }

        if ($invoice->cancellation_status === 'accepted') {
            throw new InvoiceCannotBeCancelledException($invoice, 'la cancelación ya fue aceptada');
        }

        if (in_array($invoice->cancellation_status, ['pending', 'verifying'], true)) {
            throw new InvoiceCannotBeCancelledException(
                $invoice,
                'ya existe una cancelación en curso; usa reconciliación en vez de repetir la solicitud',
            );
        }

        if (! in_array($invoice->cancellation_status, self::CANCELLABLE_STATUSES, true)) {
            throw new InvoiceCannotBeCancelledException($invoice, 'cancellation_status no es compatible');
        }
    }

    private function validatedSubstitutionUuid(
        Invoice $invoice,
        CfdiCancellationMotive $motive,
        ?string $substitutionUuid,
    ): ?string {
        if ($motive !== CfdiCancellationMotive::ErrorsWithRelation) {
            return null;
        }

        if ($substitutionUuid === null || trim($substitutionUuid) === '') {
            throw new InvoiceCannotBeCancelledException($invoice, 'el motivo 01 requiere UUID de sustitución');
        }

        if (! Str::isUuid($substitutionUuid)) {
            throw new InvoiceCannotBeCancelledException($invoice, 'el UUID de sustitución no tiene formato válido');
        }

        if ($invoice->cfdi_uuid !== null && strcasecmp($invoice->cfdi_uuid, $substitutionUuid) === 0) {
            throw new InvoiceCannotBeCancelledException($invoice, 'el UUID de sustitución no puede ser el CFDI actual');
        }

        return $substitutionUuid;
    }

    private function identityException(Invoice $invoice, PacInvoiceResult $result): ?PacUnexpectedResponseException
    {
        if ($result->externalId !== $invoice->pac_external_id) {
            return new PacUnexpectedResponseException(sprintf(
                'cancelInvoice devolvió un id remoto distinto para la factura [%d]; no se persiste la respuesta.',
                $invoice->id,
            ));
        }

        if ($result->uuid !== null
            && ($invoice->cfdi_uuid === null || strcasecmp($invoice->cfdi_uuid, $result->uuid) !== 0)) {
            return new PacUnexpectedResponseException(sprintf(
                'cancelInvoice devolvió un UUID distinto para la factura [%d]; no se modifica el UUID local.',
                $invoice->id,
            ));
        }

        if (($result->rawResponse['livemode'] ?? null) !== false) {
            if (($result->rawResponse['livemode'] ?? null) === true) {
                return new PacUnexpectedResponseException(sprintf(
                    'cancelInvoice devolvió livemode=true para la factura [%d]; no se persiste información LIVE.',
                    $invoice->id,
                ));
            }

            return new PacUnexpectedResponseException(sprintf(
                'cancelInvoice no confirmó livemode=false para la factura [%d].',
                $invoice->id,
            ));
        }

        return null;
    }

    private function requiresReconciliation(PacInvoiceResult $result): bool
    {
        return match ($result->cancellationStatus) {
            'accepted' => $result->status !== 'canceled',
            'pending', 'verifying' => true,
            'rejected', 'expired' => $result->status !== 'valid',
            default => true,
        };
    }

    private function isAmbiguous(Throwable $e): bool
    {
        return ! ($e instanceof PacValidationException
            || $e instanceof PacAuthenticationException
            || $e instanceof PacRateLimitException);
    }

    private function recordFailure(Invoice $invoice, int $tenantId, Throwable $e, bool $ambiguous): void
    {
        DB::transaction(function () use ($invoice, $tenantId, $e, $ambiguous): void {
            $locked = Invoice::withoutGlobalScope(CompanyScope::class)
                ->whereKey($invoice->getKey())
                ->where('company_id', $tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->pac_external_id !== $invoice->pac_external_id
                || strcasecmp((string) $locked->cfdi_uuid, (string) $invoice->cfdi_uuid) !== 0) {
                return;
            }

            $attributes = ['pac_last_error' => $this->sanitizeErrorMessage($e)];

            if ($ambiguous) {
                $attributes['pac_reconciliation_required'] = true;
            }

            $locked->forceFill($attributes)->save();
        });
    }

    private function requireCurrentTenantInvoice(Invoice $invoice, ?int $tenantId): Invoice
    {
        $fresh = $tenantId !== null
            ? Invoice::withoutGlobalScope(CompanyScope::class)
                ->whereKey($invoice->getKey())
                ->where('company_id', $tenantId)
                ->first()
            : null;

        if ($fresh === null) {
            throw (new ModelNotFoundException)->setModel(Invoice::class, [$invoice->getKey()]);
        }

        return $fresh;
    }

    private function logResult(
        Invoice $invoice,
        CfdiCancellationMotive $motive,
        ?PacInvoiceResult $result,
        float $startedAt,
        ?Throwable $e,
    ): void {
        Log::info('billing.invoice.pac_cancellation', array_filter([
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'pac_provider' => $invoice->pac_provider,
            'pac_external_id' => $this->maskIdentifier($invoice->pac_external_id),
            'motive' => $motive->value,
            'cancellation_status' => $result?->cancellationStatus,
            'pac_status' => $result?->status,
            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'pac_code' => $e instanceof PacException
                ? ($e->pacCode ?? (string) $e->httpStatus)
                : null,
        ], static fn ($value) => $value !== null));
    }

    private function maskIdentifier(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, 8).'…'.mb_substr($value, -4);
    }

    private function sanitizeErrorMessage(Throwable $e): string
    {
        $message = $e->getMessage();
        $apiKey = (string) config('services.facturapi.test_key');

        if ($apiKey !== '') {
            $message = str_replace($apiKey, '[redacted]', $message);
        }

        return mb_substr($message, 0, 500);
    }

    private function cancellationEventType(PacInvoiceResult $result): InvoicePacEventType
    {
        return match ($result->cancellationStatus) {
            'accepted' => InvoicePacEventType::CancellationAccepted,
            'pending', 'verifying' => InvoicePacEventType::CancellationPending,
            'rejected' => InvoicePacEventType::CancellationRejected,
            'expired' => InvoicePacEventType::CancellationExpired,
            default => InvoicePacEventType::ReconciliationRequired,
        };
    }

    private function auditReconciliationRequired(
        Invoice $invoice,
        CfdiCancellationMotive $motive,
        Throwable $error,
        float $startedAt,
    ): void {
        $this->audit->appendSafely(
            $invoice->fresh() ?? $invoice,
            InvoicePacEventType::ReconciliationRequired,
            [
                'motive' => $motive->value,
                'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'reason' => $error::class,
            ],
            $error instanceof PacException ? ($error->pacCode ?? (string) $error->httpStatus) : null,
        );
    }
}
