<?php

namespace App\Services\Payments;

use App\Contracts\Billing\PacProviderResolver;
use App\Data\Billing\PacInvoiceResult;
use App\Enums\AuditAction;
use App\Enums\PaymentStatus;
use App\Exceptions\Billing\PacAuthenticationException;
use App\Exceptions\Billing\PacConfigurationException;
use App\Exceptions\Billing\PacRateLimitException;
use App\Exceptions\Billing\PacValidationException;
use App\Infrastructure\Billing\Facturapi\FacturapiPaymentPayload;
use App\Models\AuditLog;
use App\Models\FacturapiOrganization;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Fase 6.35 (4): emite el REP (CFDI tipo P) de un pago en borrador.
 *
 * 1. Reclama el pago (estado de emisión `in_progress`) con el pago y sus
 *    facturas bloqueados; otra emisión en curso del mismo pago o de sus
 *    facturas se rechaza (la parcialidad no debe repetirse).
 * 2. Calcula en local (PaymentReceiptPlanner) y compara cada documento
 *    relacionado con `payment-summary`; si difieren, no emite (D3).
 * 3. `POST /invoices` con `idempotency_key` y `external_id`
 *    `erp-payment:{empresa}:{pago}:v1`. Si un intento anterior quedó sin
 *    respuesta clara, antes de volver a enviar busca por `external_id`.
 * 4. Guarda el REP: estado `issued`, UUID, snapshot del receptor, y en
 *    cada asignación parcialidad, saldos, snapshot de la factura e
 *    impuestos. Audita `payment_issued`.
 *
 * Errores definitivos del PAC (validación, autenticación, configuración,
 * límite) dejan el pago en borrador con `pac_issue_status = failed`. Una
 * respuesta ambigua (conexión, 5xx, respuesta inesperada) lo deja en
 * `unknown`: el siguiente intento busca primero el REP por external_id.
 */
class IssuePaymentService
{
    public const IDEMPOTENCY_VERSION = 'v1';

    /** Un reclamo `in_progress` más viejo que esto se considera abandonado. */
    private const STALE_MINUTES = 5;

    public function __construct(
        private readonly PacProviderResolver $resolver,
        private readonly PaymentReceiptPlanner $planner,
        private readonly PaymentTaxProrater $prorater,
    ) {}

    public static function idempotencyKey(Payment $payment): string
    {
        return "erp-payment:{$payment->company_id}:{$payment->id}:".self::IDEMPOTENCY_VERSION;
    }

    public function issue(Payment $payment, ?User $user = null): Payment
    {
        $claim = $this->claim($payment);
        if ($claim['already_issued']) {
            return $claim['payment'];
        }
        $payment = $claim['payment'];
        $key = self::idempotencyKey($payment);

        try {
            $plan = $this->planner->plan($payment);

            $result = $claim['ambiguous'] ? $this->resolver->forCompany((int) $payment->company_id)->findInvoiceByExternalId($key) : null;
            $summaries = [];

            if ($result === null) {
                $differences = [];
                foreach ($plan['documents'] as $document) {
                    $summary = $this->resolver->forInvoice($document['invoice'])->paymentSummary((string) $document['invoice']->pac_external_id, $document['amount']);
                    $summaries[$document['invoice']->folio] = $summary;
                    array_push($differences, ...FacturapiPaymentPayload::compare($document, $summary));
                }

                if ($differences !== []) {
                    throw new PaymentSummaryMismatchException($differences);
                }

                $payload = FacturapiPaymentPayload::build($payment, $plan, PaymentNumberGenerator::number($payment->folio), PaymentNumberGenerator::SERIES, $key);
                $result = $this->resolver->forCompany((int) $payment->company_id)->createPaymentReceipt($payload);
            }

            if ($result->status !== 'valid' || blank($result->uuid)) {
                throw new PaymentReceiptNotValidException($result->status);
            }
        } catch (Throwable $error) {
            $this->release($payment, $error);

            throw $error;
        }

        return $this->finalize($payment, $plan, $result, $summaries, $user);
    }

    /**
     * @return array{payment: Payment, already_issued: bool, ambiguous: bool}
     */
    private function claim(Payment $payment): array
    {
        return DB::transaction(function () use ($payment): array {
            $locked = Payment::withoutGlobalScope(CompanyScope::class)->lockForUpdate()->findOrFail($payment->id);

            if ($locked->status === PaymentStatus::Issued) {
                return ['payment' => $locked, 'already_issued' => true, 'ambiguous' => false];
            }
            if (! $locked->isDraft()) {
                throw new PaymentNotDraftException;
            }

            $invoiceIds = $locked->allocations()->pluck('invoice_id')->sort()->values()->all();
            Invoice::withoutGlobalScope(CompanyScope::class)->whereIn('id', $invoiceIds)->orderBy('id')->lockForUpdate()->get();

            $fresh = now()->subMinutes(self::STALE_MINUTES);
            $busy = Payment::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $locked->company_id)
                ->where('pac_issue_status', 'in_progress')
                ->where('updated_at', '>', $fresh)
                ->where(fn ($query) => $query->whereKey($locked->id)->orWhereHas(
                    'allocations', fn ($allocations) => $allocations->whereIn('invoice_id', $invoiceIds),
                ))
                ->exists();

            if ($busy) {
                throw new PaymentIssuanceInProgressException;
            }

            // Un intento anterior sin respuesta clara (o abandonado) pudo
            // crear el REP: se busca antes de volver a enviarlo.
            $ambiguous = in_array($locked->pac_issue_status, ['unknown', 'in_progress'], true);

            $locked->forceFill([
                'pac_provider' => 'facturapi',
                'pac_idempotency_key' => self::idempotencyKey($locked),
                'pac_issue_status' => 'in_progress',
                'pac_issue_attempts' => (int) $locked->pac_issue_attempts + 1,
                'pac_last_error' => null,
            ])->save();

            return ['payment' => $locked, 'already_issued' => false, 'ambiguous' => $ambiguous];
        });
    }

    private function release(Payment $payment, Throwable $error): void
    {
        $definitive = $error instanceof PaymentNotReadyException
            || $error instanceof PaymentSummaryMismatchException
            || $error instanceof PaymentNotDraftException
            || $error instanceof PacValidationException
            || $error instanceof PacAuthenticationException
            || $error instanceof PacConfigurationException
            || $error instanceof PacRateLimitException;

        $detail = $error instanceof PaymentSummaryMismatchException
            ? json_encode($error->differences, JSON_UNESCAPED_UNICODE)
            : ($error instanceof PaymentNotReadyException ? implode(' ', $error->problems) : $error->getMessage());

        Payment::withoutGlobalScope(CompanyScope::class)->whereKey($payment->id)->update([
            'pac_issue_status' => $definitive ? 'failed' : 'unknown',
            'pac_last_error' => mb_substr($error::class.': '.$detail, 0, 2000),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array{environment: ?string, customer: array<string, string>, documents: list<array<string, mixed>>}  $plan
     * @param  array<string, array<string, mixed>>  $summaries
     */
    private function finalize(Payment $payment, array $plan, PacInvoiceResult $result, array $summaries, ?User $user): Payment
    {
        return DB::transaction(function () use ($payment, $plan, $result, $summaries, $user): Payment {
            $locked = Payment::withoutGlobalScope(CompanyScope::class)->lockForUpdate()->findOrFail($payment->id);
            $organization = FacturapiOrganization::query()->where('company_id', $locked->company_id)->value('organization_id');

            $locked->forceFill([
                'status' => PaymentStatus::Issued,
                'client_name' => $plan['customer']['legal_name'],
                'client_rfc' => $plan['customer']['tax_id'],
                'client_regimen_fiscal' => $plan['customer']['tax_system'],
                'client_codigo_postal' => $plan['customer']['zip'],
                'pac_environment' => $plan['environment'],
                'pac_organization_id' => $organization,
                'pac_external_id' => $result->externalId,
                'pac_issue_status' => 'issued',
                'pac_status' => $result->status,
                'pac_response' => $result->rawResponse,
                'pac_last_error' => null,
                'cfdi_uuid' => $result->uuid,
                'stamped_at' => $result->stampedAt ?? now(),
                'issued_at' => now(),
            ])->save();

            foreach ($plan['documents'] as $document) {
                /** @var PaymentAllocation $allocation */
                $allocation = $document['allocation'];
                $allocation->forceFill([
                    'installment' => $document['installment'],
                    'previous_balance' => $document['previous_balance'],
                    'remaining_balance' => $document['remaining_balance'],
                    'invoice_uuid' => $document['uuid'],
                    'invoice_series' => $document['series'],
                    'invoice_folio_number' => $document['folio_number'],
                    'invoice_currency' => 'MXN',
                    'invoice_total' => $document['total'],
                ])->save();
                $this->prorater->store($allocation, $document['taxes']);
            }

            $this->audit($locked, $plan, $summaries, $user);

            return $locked->fresh();
        });
    }

    /**
     * @param  array{documents: list<array<string, mixed>>}  $plan
     * @param  array<string, array<string, mixed>>  $summaries
     */
    private function audit(Payment $payment, array $plan, array $summaries, ?User $user): void
    {
        $userId = $user?->id ?? $payment->created_by;
        if ($userId === null) {
            return;
        }

        AuditLog::create([
            'company_id' => $payment->company_id,
            'user_id' => $userId,
            'actor_id' => $user?->id,
            'action' => AuditAction::PaymentIssued->value,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'metadata' => [
                'payment_id' => $payment->id,
                'folio' => $payment->folio,
                'cfdi_uuid' => $payment->cfdi_uuid,
                'amount' => $payment->getRawOriginal('amount'),
                'documents' => array_map(fn (array $document): array => [
                    'invoice' => $document['invoice']->folio,
                    'installment' => $document['installment'],
                    'previous_balance' => $document['previous_balance'],
                    'amount' => $document['amount'],
                    'remaining_balance' => $document['remaining_balance'],
                    'taxes' => array_map(fn (array $tax): array => [
                        'tax' => $tax['tax'], 'kind' => $tax['kind'], 'rate' => $tax['rate'],
                        'base' => $tax['base'], 'amount' => $tax['amount'],
                    ], $document['taxes']),
                    'payment_summary' => $summaries[$document['invoice']->folio] ?? null,
                ], $plan['documents']),
            ],
            'created_at' => now(),
        ]);
    }
}
