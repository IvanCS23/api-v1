<?php

namespace App\Services\Payments;

use App\Models\Payment;
use Carbon\CarbonImmutable;

/**
 * Fase 6.35 (D9): plazo para emitir el REP. A más tardar el quinto día
 * natural del mes siguiente al del pago (regla 2.7.1.32 de la RMF 2026),
 * contado por el mes en que se cobró.
 *
 * Es un aviso, no un bloqueo: un REP fuera de plazo se puede emitir.
 * La fecha del pago se guarda como hora local de México (como se capturó);
 * "hoy" también se toma en esa zona.
 */
final class PaymentReceiptDeadline
{
    /** Días antes del plazo en que se avisa que vence pronto. */
    public const DUE_SOON_DAYS = 3;

    public const ON_TIME = 'on_time';

    public const DUE_SOON = 'due_soon';

    public const OVERDUE = 'overdue';

    public static function for(CarbonImmutable $paymentDate): CarbonImmutable
    {
        return $paymentDate->startOfMonth()->addMonthNoOverflow()->addDays(4)->startOfDay();
    }

    /** Estado del plazo de un pago en borrador; null si ya tiene REP. */
    public static function status(Payment $payment, ?CarbonImmutable $today = null): ?string
    {
        if (! $payment->isDraft() || $payment->payment_date === null) {
            return null;
        }

        $today ??= self::today();
        $deadline = self::for($payment->payment_date);

        return match (true) {
            $today->greaterThan($deadline) => self::OVERDUE,
            $today->greaterThanOrEqualTo($deadline->subDays(self::DUE_SOON_DAYS)) => self::DUE_SOON,
            default => self::ON_TIME,
        };
    }

    /** ¿El REP se emitió (o se emitiría hoy) después del plazo? */
    public static function isLate(Payment $payment, ?CarbonImmutable $today = null): bool
    {
        return $payment->payment_date !== null
            && ($today ?? self::today())->greaterThan(self::for($payment->payment_date));
    }

    private static function today(): CarbonImmutable
    {
        $zone = (string) config('billing.facturapi.stamp_timezone', 'America/Mexico_City');

        return CarbonImmutable::parse(now($zone)->toDateString());
    }
}
