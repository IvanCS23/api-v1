<?php

namespace App\Services\Billing;

use App\Contracts\Billing\PacProvider;
use App\Data\Billing\PacInvoiceResult;
use App\Enums\InvoicePacEventType;
use App\Events\Billing\InvoiceIssued;
use App\Exceptions\Billing\PacAmbiguousInvoiceMatchException;
use App\Exceptions\Billing\PacException;
use App\Exceptions\Billing\PacUnexpectedEnvironmentException;
use App\Exceptions\Billing\PacUnexpectedResponseException;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Support\Billing\PacIdentifiers;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Consulta el recurso remoto existente y sincroniza únicamente metadata
 * PAC/fiscal. Nunca emite, timbra, cancela ni descarga artifacts.
 *
 * El HTTP ocurre antes de la transacción. Después de recibir la respuesta,
 * la Invoice se relee bajo lock y se vuelven a comprobar tenant e identidad
 * antes de persistir. El fallback por external_id se conserva exclusivamente
 * para recuperar intentos ambiguos de fases anteriores que todavía no tienen
 * pac_external_id; una Invoice ya identificada siempre usa retrieveInvoice().
 */
class ReconcileInvoiceWithPacService
{
    private const REMOTE_STATUSES = ['draft', 'pending', 'valid', 'canceled'];

    public function __construct(
        private readonly PacProvider $pacProvider,
        private readonly InvoicePacAuditService $audit,
    ) {}

    public function reconcile(Invoice $invoice): Invoice
    {
        $tenantId = app(CurrentTenant::class)->id();
        $current = $this->requireCurrentTenantInvoice($invoice, $tenantId);

        if ($current->pac_external_id === null
            && $current->pac_draft_external_id === null
            && $current->pac_idempotency_key === null
            && $current->pac_draft_idempotency_key === null) {
            throw new RuntimeException(sprintf(
                'La factura [%d] no tiene pac_external_id ni contexto previo de emisión ante el PAC; no hay nada que reconciliar.',
                $current->id,
            ));
        }

        $requestedRemoteId = $current->pac_external_id ?? $current->pac_draft_external_id;
        $startedAt = microtime(true);

        try {
            $result = match (true) {
                $current->pac_external_id !== null => $this->pacProvider->retrieveInvoice($current->pac_external_id),
                $current->pac_draft_external_id !== null => $this->pacProvider->retrieveInvoice($current->pac_draft_external_id),
                default => $this->pacProvider->findInvoiceByExternalId(
                    PacIdentifiers::externalId($tenantId, $current->id),
                ),
            };
        } catch (PacUnexpectedEnvironmentException $e) {
            $this->recordReconciliationFailure($current, $tenantId, $e, 'billing.invoice.pac_reconciliation_wrong_environment');

            throw $e;
        } catch (PacAmbiguousInvoiceMatchException $e) {
            $this->recordReconciliationFailure($current, $tenantId, $e, 'billing.invoice.pac_reconciliation_ambiguous');

            return $current->fresh();
        } catch (Throwable $e) {
            $this->recordReconciliationFailure($current, $tenantId, $e, 'billing.invoice.pac_reconciliation_failed');

            return $current->fresh();
        }

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($result === null) {
            Log::info('billing.invoice.pac_reconciliation_not_found', $this->safeLogContext($current, $elapsedMs));
            $this->audit->appendSafely($current, InvoicePacEventType::ReconciliationRequired, [
                'elapsed_ms' => $elapsedMs,
                'reason' => 'remote_invoice_not_found',
            ]);

            return $current;
        }

        if ($requestedRemoteId !== null && $result->externalId !== $requestedRemoteId) {
            $exception = $this->remoteIdMismatch($current, $requestedRemoteId, $result->externalId);
            $this->recordReconciliationFailure($current, $tenantId, $exception, 'billing.invoice.pac_reconciliation_identity_mismatch');

            throw $exception;
        }

        if ($exception = $this->uuidException($current, $result)) {
            $this->recordReconciliationFailure($current, $tenantId, $exception, 'billing.invoice.pac_reconciliation_uuid_mismatch');

            throw $exception;
        }

        $providerSlug = $this->pacProvider->name();
        $postCommitException = null;

        $updated = DB::transaction(function () use (
            $current,
            $tenantId,
            $requestedRemoteId,
            $result,
            $providerSlug,
            &$postCommitException,
        ): Invoice {
            if (app(CurrentTenant::class)->id() !== $tenantId) {
                throw (new ModelNotFoundException)->setModel(Invoice::class, [$current->getKey()]);
            }

            $locked = Invoice::withoutGlobalScope(CompanyScope::class)
                ->whereKey($current->getKey())
                ->where('company_id', $tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            // Otra ejecución resolvió/cambió la identidad mientras ocurría el
            // HTTP. La respuesta tardía se descarta sin pisar al ganador.
            if ($requestedRemoteId === null && $locked->pac_external_id !== null) {
                return $locked;
            }

            if ($requestedRemoteId !== null
                && $locked->pac_external_id !== null
                && $locked->pac_external_id !== $requestedRemoteId) {
                return $locked;
            }

            $expectedRemoteId = $locked->pac_external_id ?? $locked->pac_draft_external_id;

            if ($expectedRemoteId !== null && $result->externalId !== $expectedRemoteId) {
                $postCommitException = $this->remoteIdMismatch($locked, $expectedRemoteId, $result->externalId);

                return $this->markRequiredUnderLock($locked, $postCommitException);
            }

            if ($exception = $this->uuidException($locked, $result)) {
                $postCommitException = $exception;

                return $this->markRequiredUnderLock($locked, $exception);
            }

            $hadCfdiBefore = $locked->cfdi_uuid !== null;
            $isKnownStatus = in_array($result->status, self::REMOTE_STATUSES, true);
            $isValid = $result->status === 'valid';
            $artifactsStored = $locked->cfdi_artifacts_status === 'stored';

            $attributes = [
                'pac_provider' => $locked->pac_provider ?? $providerSlug,
                'pac_external_id' => $locked->pac_external_id ?? $result->externalId,
                'pac_status' => $result->status,
                'cancellation_status' => $result->cancellationStatus,
                'last_pac_sync_at' => now(),
                'pac_response' => $result->rawResponse,
                'pac_last_error' => null,
                'pac_reconciliation_required' => match ($result->status) {
                    'valid' => ! $artifactsStored,
                    'canceled' => false,
                    default => true,
                },
            ];

            if ($isValid && $locked->cfdi_uuid === null) {
                $attributes['cfdi_uuid'] = $result->uuid;
            }

            if ($isValid && $locked->stamped_at === null && $result->stampedAt !== null) {
                $attributes['stamped_at'] = $result->stampedAt;
            }

            if ($isValid) {
                $attributes['pac_issue_status'] = 'succeeded';
            } elseif ($locked->pac_issue_status !== 'succeeded' && $isKnownStatus) {
                $attributes['pac_issue_status'] = 'pending';
            }

            if ($locked->pac_draft_external_id === $result->externalId) {
                $attributes['pac_draft_status'] = $result->status;
            }

            $locked->forceFill($attributes)->save();

            if ($isValid && ! $hadCfdiBefore) {
                DB::afterCommit(function () use ($locked, $result): void {
                    event(new InvoiceIssued($locked, $result));
                });
            }

            return $locked;
        });

        if ($postCommitException !== null) {
            $this->audit->appendSafely($updated, InvoicePacEventType::ReconciliationRequired, [
                'elapsed_ms' => $elapsedMs,
                'reason' => $postCommitException::class,
            ]);

            throw $postCommitException;
        }

        Log::info('billing.invoice.pac_reconciled', $this->safeLogContext($updated, $elapsedMs));

        $changedFields = $this->relevantChanges($current, $updated);

        if ($changedFields !== []) {
            $this->audit->appendSafely($updated, InvoicePacEventType::Reconciled, [
                'elapsed_ms' => $elapsedMs,
                'changed_fields' => $changedFields,
            ]);
        }

        return $updated;
    }

    private function uuidException(Invoice $invoice, PacInvoiceResult $result): ?PacUnexpectedResponseException
    {
        if ($result->uuid !== null && ! Str::isUuid($result->uuid)) {
            return new PacUnexpectedResponseException(sprintf(
                'El PAC devolvió un UUID inválido al reconciliar la factura [%d]; no se modifica el UUID local.',
                $invoice->id,
            ));
        }

        if ($invoice->cfdi_uuid !== null
            && $result->uuid !== null
            && strcasecmp($invoice->cfdi_uuid, $result->uuid) !== 0) {
            return new PacUnexpectedResponseException(sprintf(
                'El UUID remoto no coincide con cfdi_uuid para la factura [%d]; no se sobrescribe el UUID local.',
                $invoice->id,
            ));
        }

        if ($result->status === 'valid' && $result->uuid === null) {
            return new PacUnexpectedResponseException(sprintf(
                'El PAC devolvió status valid sin UUID para la factura [%d]; no se persiste la respuesta como válida.',
                $invoice->id,
            ));
        }

        if ($invoice->cfdi_uuid === null
            && $result->status === 'valid'
            && ($result->rawResponse['livemode'] ?? null) !== false) {
            return new PacUnexpectedResponseException(sprintf(
                'No se puede recuperar cfdi_uuid para la factura [%d] sin confirmar livemode=false.',
                $invoice->id,
            ));
        }

        return null;
    }

    private function remoteIdMismatch(Invoice $invoice, string $expected, string $received): PacUnexpectedResponseException
    {
        return new PacUnexpectedResponseException(sprintf(
            'El PAC devolvió un id remoto distinto al pac_external_id esperado para la factura [%d] (esperado: %s; recibido: %s).',
            $invoice->id,
            $expected,
            $received,
        ));
    }

    private function markRequiredUnderLock(Invoice $invoice, Throwable $e): Invoice
    {
        $invoice->forceFill([
            'pac_reconciliation_required' => true,
            'pac_last_error' => $this->sanitizeErrorMessage($e),
        ])->save();

        return $invoice;
    }

    private function recordReconciliationFailure(Invoice $invoice, int $tenantId, Throwable $e, string $logEvent): void
    {
        DB::transaction(function () use ($invoice, $tenantId, $e): void {
            $locked = Invoice::withoutGlobalScope(CompanyScope::class)
                ->whereKey($invoice->getKey())
                ->where('company_id', $tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->markRequiredUnderLock($locked, $e);
        });

        Log::info($logEvent, [
            'invoice_id' => $invoice->id,
            'company_id' => $tenantId,
            'pac_provider' => $invoice->pac_provider,
        ]);

        $fresh = $invoice->fresh() ?? $invoice;
        $this->audit->appendSafely($fresh, InvoicePacEventType::ReconciliationRequired, [
            'reason' => $logEvent,
        ], $e instanceof PacException ? ($e->pacCode ?? (string) $e->httpStatus) : null);
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

    /** @return array<string, int|string|null> */
    private function safeLogContext(Invoice $invoice, int $elapsedMs): array
    {
        return [
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'pac_provider' => $invoice->pac_provider,
            'pac_issue_status' => $invoice->pac_issue_status,
            'elapsed_ms' => $elapsedMs,
        ];
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

    /** @return list<string> */
    private function relevantChanges(Invoice $before, Invoice $after): array
    {
        $fields = [
            'pac_status',
            'cancellation_status',
            'cfdi_uuid',
            'pac_external_id',
            'pac_issue_status',
            'pac_reconciliation_required',
        ];

        return array_values(array_filter(
            $fields,
            fn (string $field): bool => $before->getAttribute($field) !== $after->getAttribute($field),
        ));
    }
}
