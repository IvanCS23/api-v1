<?php

namespace App\Services\Billing;

use App\Models\Invoice;

/**
 * Carga exclusivamente la timeline local necesaria para el contrato
 * InvoiceBillingResource. No consulta Storage ni resuelve PacProvider.
 */
class LoadInvoiceBillingSnapshotService
{
    public const DEFAULT_TIMELINE_LIMIT = 50;

    public const MAX_TIMELINE_LIMIT = 100;

    public function load(Invoice $invoice, int $timelineLimit = self::DEFAULT_TIMELINE_LIMIT): Invoice
    {
        $limit = min(max($timelineLimit, 1), self::MAX_TIMELINE_LIMIT);
        $events = $invoice->pacEvents()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->sortBy([
                ['occurred_at', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        $invoice->setRelation('pacEvents', $events);

        return $invoice;
    }
}
