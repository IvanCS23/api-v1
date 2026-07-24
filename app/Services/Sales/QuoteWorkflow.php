<?php

namespace App\Services\Sales;

use App\Enums\QuoteStatus;
use App\Exceptions\WorkflowTransitionException;
use App\Models\Quote;

/**
 * Única fuente de verdad de las transiciones de estado "manuales" de una
 * Quote: send/approve/reject/expire. Approved→Converted queda fuera a
 * propósito — esa transición es responsabilidad exclusiva de
 * QuoteToSaleConverter (incluye crear la Sale y fijar converted_at junto
 * con converted_sale_id), y no se duplica aquí.
 *
 * `approved_at` lo fija exclusivamente approve() — nunca el endpoint
 * genérico de update (ver UpdateQuoteRequest, Fase 4 §5).
 */
class QuoteWorkflow
{
    public function send(Quote $quote): Quote
    {
        $this->assertTransition($quote, [QuoteStatus::Draft], 'enviar');

        $quote->update(['status' => QuoteStatus::Sent]);

        return $quote;
    }

    public function approve(Quote $quote): Quote
    {
        $this->assertTransition($quote, [QuoteStatus::Sent], 'aprobar');

        $quote->forceFill([
            'status' => QuoteStatus::Approved,
            'approved_at' => now(),
        ])->save();

        return $quote;
    }

    public function reject(Quote $quote): Quote
    {
        $this->assertTransition($quote, [QuoteStatus::Draft, QuoteStatus::Sent], 'rechazar');

        $quote->update(['status' => QuoteStatus::Rejected]);

        return $quote;
    }

    public function expire(Quote $quote): Quote
    {
        $this->assertTransition($quote, [QuoteStatus::Sent], 'expirar');

        $quote->update(['status' => QuoteStatus::Expired]);

        return $quote;
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
