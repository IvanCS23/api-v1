<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Support\Billing\InvoiceFolio;
use Illuminate\Support\Facades\DB;

/**
 * Genera el folio interno consecutivo de una Invoice, independiente por
 * empresa (formato FAC-00000001) — mismo mecanismo de bloqueo que
 * SaleNumberGenerator/QuoteNumberGenerator. Fase 6.28: el CFDI toma de
 * aquí su serie y folio (InvoiceFolio), así que el prefijo es el mismo.
 */
class InvoiceNumberGenerator
{
    private const PREFIX = InvoiceFolio::PREFIX;

    private const PAD_LENGTH = 8;

    public function next(int $companyId): string
    {
        return DB::transaction(function () use ($companyId): string {
            // Fase 6.25: incluye las borradas (suave). El único (company_id,
            // folio) también las cuenta; sin esto, borrar el último registro
            // y crear otro repetía el folio (error 500).
            $lastFolio = Invoice::withoutGlobalScope(CompanyScope::class)
                ->withTrashed()
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
