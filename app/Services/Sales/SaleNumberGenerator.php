<?php

namespace App\Services\Sales;

use App\Models\Sale;
use App\Models\Scopes\CompanyScope;
use Illuminate\Support\Facades\DB;

/**
 * Genera el folio interno consecutivo de una venta, independiente por
 * empresa (dos empresas distintas pueden tener ambas un "VTA-00000001").
 * No tiene ninguna relación con el folio fiscal del CFDI — eso es Fase 3.
 */
class SaleNumberGenerator
{
    private const PREFIX = 'VTA-';

    private const PAD_LENGTH = 8;

    public function next(int $companyId): string
    {
        return DB::transaction(function () use ($companyId): string {
            $lastFolio = Sale::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value('folio');

            $nextSequence = $lastFolio !== null
                ? ((int) substr($lastFolio, strlen(self::PREFIX))) + 1
                : 1;

            return self::PREFIX.str_pad((string) $nextSequence, self::PAD_LENGTH, '0', STR_PAD_LEFT);
        });
    }
}
