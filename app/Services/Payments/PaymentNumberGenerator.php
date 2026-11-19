<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\Scopes\CompanyScope;
use Illuminate\Support\Facades\DB;

/**
 * Fase 6.35 (D6): folio de los pagos con serie propia "PAG" por empresa
 * (PAG-00000001). Cuenta los borrados en suave, como InvoiceNumberGenerator.
 */
class PaymentNumberGenerator
{
    public const SERIES = 'PAG';

    public const PREFIX = self::SERIES.'-';

    private const PAD_LENGTH = 8;

    public function next(int $companyId): string
    {
        return DB::transaction(function () use ($companyId): string {
            $lastFolio = Payment::withoutGlobalScope(CompanyScope::class)
                ->withTrashed()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value('folio');

            $next = $lastFolio !== null ? ((int) substr($lastFolio, strlen(self::PREFIX))) + 1 : 1;

            return self::PREFIX.str_pad((string) $next, self::PAD_LENGTH, '0', STR_PAD_LEFT);
        });
    }

    /** "PAG-00000012" → 12. */
    public static function number(string $folio): int
    {
        return (int) substr($folio, strlen(self::PREFIX));
    }
}
