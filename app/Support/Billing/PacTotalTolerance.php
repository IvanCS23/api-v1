<?php

namespace App\Support\Billing;

use App\Services\Taxes\DecimalMath;

/**
 * Fase 6.34 (6): tolerancia entre el total local y el del borrador del PAC
 * antes de timbrar. La define el paso 7 (comparación contra Facturapi
 * TEST): si los casos coinciden al centavo, cualquier diferencia bloquea
 * (0 centavos).
 */
final class PacTotalTolerance
{
    public const MAX_DIFFERENCE_CENTS = 0;

    /** Diferencia en centavos (valor absoluto) entre dos importes. */
    public static function differenceCents(mixed $local, mixed $remote): int
    {
        $difference = DecimalMath::sub(DecimalMath::round(DecimalMath::of($local), 2), DecimalMath::round(DecimalMath::of($remote), 2));

        return abs((int) bcmul($difference, '100', 0));
    }

    public static function matches(mixed $local, mixed $remote): bool
    {
        return self::differenceCents($local, $remote) <= self::MAX_DIFFERENCE_CENTS;
    }
}
