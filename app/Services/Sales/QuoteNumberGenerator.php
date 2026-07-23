<?php

namespace App\Services\Sales;

use App\Models\Quote;
use App\Models\Scopes\CompanyScope;
use Illuminate\Support\Facades\DB;

/**
 * Genera el folio interno consecutivo de una cotización, independiente
 * por empresa (formato COT-00000001) — misma lógica de bloqueo que
 * SaleNumberGenerator, aplicada a `quotes`.
 *
 * No se generalizó en un servicio compartido a propósito: el refactor
 * invitado en Fase 3 §5 aplicaba explícitamente al calculador, no al
 * generador de folios; duplicar esta clase pequeña (bloqueo +
 * consecutivo) es más simple y seguro que introducir una abstracción
 * extra no pedida.
 */
class QuoteNumberGenerator
{
    private const PREFIX = 'COT-';

    private const PAD_LENGTH = 8;

    public function next(int $companyId): string
    {
        return DB::transaction(function () use ($companyId): string {
            $lastFolio = Quote::withoutGlobalScope(CompanyScope::class)
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
