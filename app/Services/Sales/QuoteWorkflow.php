<?php

namespace App\Services\Sales;

use App\Enums\QuoteStatus;
use App\Exceptions\WorkflowTransitionException;
use App\Models\Quote;
use App\Models\Scopes\CompanyScope;
use Illuminate\Support\Facades\DB;

/**
 * Única fuente de verdad de las transiciones de estado "manuales" de una
 * Quote: send/approve/reject/expire. Approved→Converted queda fuera a
 * propósito — esa transición es responsabilidad exclusiva de
 * QuoteToSaleConverter (incluye crear la Sale y fijar converted_at junto
 * con converted_sale_id), y no se duplica aquí.
 *
 * `approved_at` lo fija exclusivamente approve() — nunca el endpoint
 * genérico de update (ver UpdateQuoteRequest, Fase 4 §5).
 *
 * Concurrencia (auditoría Fase 4 — cierre): mismo patrón que
 * SaleWorkflow y que QuoteToSaleConverter — cada transición corre dentro
 * de DB::transaction(), relee la Quote con lockForUpdate() y valida y
 * actualiza sobre esa relectura, nunca sobre la instancia recibida como
 * parámetro.
 */
class QuoteWorkflow
{
    public function send(Quote $quote): Quote
    {
        return DB::transaction(function () use ($quote): Quote {
            $locked = $this->lockedQuote($quote);

            $this->assertTransition($locked, [QuoteStatus::Draft], 'enviar');

            $locked->update(['status' => QuoteStatus::Sent]);

            return $locked;
        });
    }

    public function approve(Quote $quote): Quote
    {
        return DB::transaction(function () use ($quote): Quote {
            $locked = $this->lockedQuote($quote);

            $this->assertTransition($locked, [QuoteStatus::Sent], 'aprobar');

            $locked->forceFill([
                'status' => QuoteStatus::Approved,
                'approved_at' => now(),
            ])->save();

            return $locked;
        });
    }

    public function reject(Quote $quote): Quote
    {
        return DB::transaction(function () use ($quote): Quote {
            $locked = $this->lockedQuote($quote);

            $this->assertTransition($locked, [QuoteStatus::Draft, QuoteStatus::Sent], 'rechazar');

            $locked->update(['status' => QuoteStatus::Rejected]);

            return $locked;
        });
    }

    public function expire(Quote $quote): Quote
    {
        return DB::transaction(function () use ($quote): Quote {
            $locked = $this->lockedQuote($quote);

            $this->assertTransition($locked, [QuoteStatus::Sent], 'expirar');

            $locked->update(['status' => QuoteStatus::Expired]);

            return $locked;
        });
    }

    /**
     * Relee la Quote con lockForUpdate() dentro de la transacción
     * activa. Mismo razonamiento que SaleWorkflow::lockedSale(): el
     * `where('company_id', ...)` explícito usa el company_id de la
     * instancia ya tenant-scoped recibida (nunca del request) y evita
     * que withoutGlobalScope() por sí solo permita bloquear/modificar
     * una Quote de otra empresa.
     */
    private function lockedQuote(Quote $quote): Quote
    {
        return Quote::withoutGlobalScope(CompanyScope::class)
            ->whereKey($quote->getKey())
            ->where('company_id', $quote->company_id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @param  array<int, QuoteStatus>  $allowedFrom
     */
    private function assertTransition(Quote $quote, array $allowedFrom, string $actionLabel): void
    {
        if (! in_array($quote->status, $allowedFrom, true)) {
            throw new WorkflowTransitionException(sprintf(
                'No se puede %s una cotización en estado "%s".',
                $actionLabel,
                $quote->status->value,
            ));
        }
    }
}
