<?php

namespace App\Services\Payments;

use App\Contracts\Billing\PacProviderResolver;
use App\Data\Billing\PacInvoiceResult;
use App\Enums\AuditAction;
use App\Enums\CfdiCancellationMotive;
use App\Enums\PaymentStatus;
use App\Exceptions\Billing\PacAuthenticationException;
use App\Exceptions\Billing\PacConfigurationException;
use App\Exceptions\Billing\PacRateLimitException;
use App\Exceptions\Billing\PacValidationException;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Fase 6.35 (5): cancelación del REP y sincronización de su estado.
 *
 * - Motivos: sólo 02 y 03, en ambos ambientes (ajuste 2: en Live no hay
 *   sustitución; el 01 tampoco se ofrece en Test porque no existe el flujo
 *   de REP sustituto).
 * - El REP siempre requiere aceptación del receptor (ajuste 1), así que lo
 *   normal es quedar `pending`. El pago sólo pasa a `cancelled` (y su monto
 *   vuelve al saldo de la factura) cuando el PAC reporta el CFDI cancelado.
 *   Pendiente o rechazada, el pago sigue contando.
 * - El DELETE no se reintenta: si la respuesta es ambigua la solicitud
 *   queda `unknown` y se resuelve con sync().
 */
class CancelPaymentService
{
    public const MOTIVES = [CfdiCancellationMotive::ErrorsWithoutRelation, CfdiCancellationMotive::OperationNotPerformed];

    /** Una solicitud `requesting` más vieja que esto se considera abandonada. */
    private const STALE_MINUTES = 5;

    public function __construct(private readonly PacProviderResolver $resolver) {}

    /** @return list<string> */
    public static function motives(): array
    {
        return array_map(fn (CfdiCancellationMotive $motive): string => $motive->value, self::MOTIVES);
    }

    public function cancel(Payment $payment, string $motive, ?User $user = null): Payment
    {
        if (! in_array($motive, self::motives(), true)) {
            throw new PaymentCancellationMotiveException($motive);
        }

        $claim = DB::transaction(function () use ($payment, $motive): array {
            $locked = Payment::withoutGlobalScope(CompanyScope::class)->lockForUpdate()->findOrFail($payment->id);

            if ($locked->status === PaymentStatus::Cancelled || $locked->cancellation_status === 'pending') {
                return ['payment' => $locked, 'send' => false];
            }
            if ($locked->status !== PaymentStatus::Issued || blank($locked->pac_external_id)) {
                throw new PaymentNotIssuedException;
            }
            if (in_array($locked->cancellation_status, ['requesting', 'unknown'], true)
                && ($locked->cancellation_status === 'unknown' || $locked->updated_at?->greaterThan(now()->subMinutes(self::STALE_MINUTES)))) {
                throw new PaymentCancellationInProgressException;
            }

            $previous = $locked->cancellation_status;
            $locked->forceFill([
                'cancellation_status' => 'requesting',
                'cancellation_motive' => $motive,
                'cancellation_requested_at' => now(),
                'pac_last_error' => null,
            ])->save();

            return ['payment' => $locked, 'send' => true, 'previous' => $previous];
        });

        if (! $claim['send']) {
            return $claim['payment'];
        }

        $payment = $claim['payment'];

        try {
            $result = $this->resolver->forCompany((int) $payment->company_id)->cancelInvoice((string) $payment->pac_external_id, $motive);
        } catch (Throwable $error) {
            $definitive = $error instanceof PacValidationException
                || $error instanceof PacAuthenticationException
                || $error instanceof PacConfigurationException
                || $error instanceof PacRateLimitException;

            Payment::withoutGlobalScope(CompanyScope::class)->whereKey($payment->id)->update([
                // Un rechazo claro deja el REP como estaba; uno ambiguo se
                // resuelve consultando el PAC (sync).
                'cancellation_status' => $definitive ? $claim['previous'] : 'unknown',
                'pac_last_error' => mb_substr($error::class.': '.$error->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);

            throw $error;
        }

        $payment = $this->apply($payment, $result);
        $this->audit($payment, $motive, $user);

        return $payment;
    }

    /** Consulta el REP en el PAC y actualiza el estado de su cancelación. */
    public function sync(Payment $payment): Payment
    {
        if (blank($payment->pac_external_id)) {
            throw new PaymentNotIssuedException;
        }

        $result = $this->resolver->forCompany((int) $payment->company_id)->retrieveInvoice((string) $payment->pac_external_id);

        return $this->apply($payment, $result);
    }

    private function apply(Payment $payment, PacInvoiceResult $result): Payment
    {
        return DB::transaction(function () use ($payment, $result): Payment {
            $locked = Payment::withoutGlobalScope(CompanyScope::class)->lockForUpdate()->findOrFail($payment->id);

            if ($result->status === 'canceled') {
                $locked->forceFill([
                    'status' => PaymentStatus::Cancelled,
                    'pac_status' => 'canceled',
                    'cancellation_status' => 'accepted',
                    'cancelled_at' => $locked->cancelled_at ?? now(),
                ]);
            } else {
                $remote = $result->cancellationStatus;
                $locked->forceFill([
                    'pac_status' => $result->status,
                    // Sin solicitud en el PAC ("none"), una solicitud local
                    // ambigua no llegó: se limpia.
                    'cancellation_status' => match ($remote) {
                        'pending', 'rejected', 'expired' => $remote,
                        default => in_array($locked->cancellation_status, ['requesting', 'unknown'], true) ? null : $locked->cancellation_status,
                    },
                ]);
            }

            $locked->save();

            return $locked->fresh();
        });
    }

    private function audit(Payment $payment, string $motive, ?User $user): void
    {
        $userId = $user?->id ?? $payment->created_by;
        if ($userId === null) {
            return;
        }

        AuditLog::create([
            'company_id' => $payment->company_id,
            'user_id' => $userId,
            'actor_id' => $user?->id,
            'action' => AuditAction::PaymentCancellationRequested->value,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'metadata' => [
                'payment_id' => $payment->id,
                'folio' => $payment->folio,
                'cfdi_uuid' => $payment->cfdi_uuid,
                'motive' => $motive,
                'status' => $payment->status->value,
                'cancellation_status' => $payment->cancellation_status,
            ],
            'created_at' => now(),
        ]);
    }
}
