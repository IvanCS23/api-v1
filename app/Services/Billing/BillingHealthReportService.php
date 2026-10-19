<?php

namespace App\Services\Billing;

use App\Enums\InvoiceHealthStatus;
use App\Enums\InvoicePacEventType;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoicePacEvent;
use App\Support\Billing\InvoiceHealthFinding;
use App\Support\Billing\InvoiceHealthReport;
use App\Support\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Reporte agregado de salud del ciclo de facturación (Fase 6.18).
 *
 * Read-only: sólo emite SELECT, nunca hace HTTP ni modifica facturas o
 * eventos. Recorre empresa por empresa fijando CurrentTenant, de modo
 * que todas las consultas pasan por CompanyScope sin desactivarlo, y
 * restaura el tenant previo al terminar.
 */
class BillingHealthReportService
{
    /** Columnas estrictamente necesarias: nunca payloads PAC, errores ni rutas. */
    private const COLUMNS = [
        'id', 'company_id', 'folio', 'status', 'updated_at',
        'pac_external_id', 'cfdi_uuid', 'pac_status', 'cancellation_status', 'last_pac_sync_at',
        'pac_idempotency_key', 'pac_issue_status', 'pac_issue_started_at', 'pac_reconciliation_required',
        'pac_draft_external_id', 'pac_draft_status', 'pac_draft_idempotency_key', 'pac_draft_created_at',
        'cfdi_artifacts_status', 'cancellation_receipt_status',
    ];

    public function __construct(
        private readonly InvoiceHealthEvaluator $evaluator,
        private readonly CurrentTenant $tenant,
    ) {}

    /**
     * @param  list<int>  $companyIds
     * @param  list<InvoiceHealthStatus>  $listStatuses  estados a detallar en `invoices`
     * @return array<string, mixed>
     */
    public function generate(
        array $companyIds,
        CarbonImmutable $now,
        array $listStatuses,
        int $limit,
        ?int $invoiceId = null,
    ): array {
        $state = [
            'totals' => array_fill_keys(array_map(fn (InvoiceHealthStatus $s) => $s->value, InvoiceHealthStatus::cases()), 0),
            'by_code' => [],
            'by_state' => [],
            'ready_aged' => 0,
            'invoices' => [],
            'listed_candidates' => 0,
            'worst' => InvoiceHealthStatus::Valid,
        ];

        $previousTenant = $this->tenant->id();

        try {
            foreach ($companyIds as $companyId) {
                $this->tenant->set($companyId);
                $this->evaluateCompany($state, $now, $listStatuses, $limit, $invoiceId);
            }
        } finally {
            $previousTenant === null ? $this->tenant->clear() : $this->tenant->set($previousTenant);
        }

        ksort($state['by_code']);
        ksort($state['by_state']);

        return [
            'generated_at' => $now->toIso8601String(),
            'read_only' => true,
            'scope' => [
                'companies' => count($companyIds),
                'invoice' => $invoiceId,
            ],
            'thresholds' => [
                'issue_pending_minutes' => (int) config('billing.health.issue_pending_minutes'),
                'cancellation_pending_hours' => (int) config('billing.health.cancellation_pending_hours'),
                'draft_date_warning_hours' => (int) config('billing.health.draft_date_warning_hours'),
                'draft_date_limit_hours' => (int) config('billing.health.draft_date_limit_hours'),
            ],
            'evaluated' => array_sum($state['totals']),
            'worst' => $state['worst']->value,
            'totals' => $state['totals'],
            'by_code' => $state['by_code'],
            'by_state' => $state['by_state'],
            'informational' => [
                'ready_without_remote_draft_over_limit' => $state['ready_aged'],
            ],
            'invoices' => $state['invoices'],
            'truncated' => $state['listed_candidates'] > count($state['invoices']),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<InvoiceHealthStatus>  $listStatuses
     */
    private function evaluateCompany(array &$state, CarbonImmutable $now, array $listStatuses, int $limit, ?int $invoiceId): void
    {
        $readyLimit = $now->subHours((int) config('billing.health.draft_date_limit_hours'));

        Invoice::query()
            ->select(self::COLUMNS)
            ->when($invoiceId !== null, fn ($query) => $query->whereKey($invoiceId))
            ->chunkById(max(1, (int) config('billing.health.chunk_size')), function (Collection $invoices) use (&$state, $now, $listStatuses, $limit, $readyLimit): void {
                $requestedAt = $this->cancellationRequestedAt($invoices);

                foreach ($invoices as $invoice) {
                    $report = $this->evaluator->evaluate($invoice, $now, $requestedAt[$invoice->id] ?? null);
                    $this->accumulate($state, $invoice, $report, $listStatuses, $limit);

                    if ($invoice->status === InvoiceStatus::Ready
                        && blank($invoice->pac_draft_external_id)
                        && $invoice->updated_at !== null
                        && $invoice->updated_at->lt($readyLimit)) {
                        $state['ready_aged']++;
                    }
                }
            });
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<InvoiceHealthStatus>  $listStatuses
     */
    private function accumulate(array &$state, Invoice $invoice, InvoiceHealthReport $report, array $listStatuses, int $limit): void
    {
        $state['totals'][$report->status->value]++;
        $state['worst'] = InvoiceHealthStatus::worst($state['worst'], $report->status);

        foreach (array_unique($report->codes()) as $code) {
            $state['by_code'][$code] = ($state['by_code'][$code] ?? 0) + 1;
        }

        $stateKey = sprintf('%s|%s', $invoice->status?->value ?? 'none', $invoice->pac_status ?? 'none');
        $state['by_state'][$stateKey] = ($state['by_state'][$stateKey] ?? 0) + 1;

        if (! in_array($report->status, $listStatuses, true)) {
            return;
        }

        $state['listed_candidates']++;

        if (count($state['invoices']) >= $limit) {
            return;
        }

        $state['invoices'][] = [
            'id' => $report->invoiceId,
            'company_id' => $report->companyId,
            'folio' => $invoice->folio,
            'status' => $invoice->status?->value,
            'pac_status' => $invoice->pac_status,
            'pac_issue_status' => $invoice->pac_issue_status,
            'cancellation_status' => $invoice->cancellation_status,
            'health' => $report->status->value,
            'findings' => array_map(fn (InvoiceHealthFinding $finding): array => $finding->toArray(), $report->findings),
        ];
    }

    /**
     * Último CancellationRequested por factura con cancelación pendiente.
     * Una sola consulta por chunk, cubierta por (company_id, event_type).
     *
     * @param  Collection<int, Invoice>  $invoices
     * @return array<int, CarbonImmutable>
     */
    private function cancellationRequestedAt(Collection $invoices): array
    {
        $ids = $invoices
            ->filter(fn (Invoice $invoice): bool => $invoice->pac_status === 'valid'
                && in_array($invoice->cancellation_status, ['pending', 'verifying'], true))
            ->modelKeys();

        if ($ids === []) {
            return [];
        }

        return InvoicePacEvent::query()
            ->where('event_type', InvoicePacEventType::CancellationRequested->value)
            ->whereIn('invoice_id', $ids)
            ->groupBy('invoice_id')
            ->selectRaw('invoice_id, max(occurred_at) as requested_at')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                (int) $row->invoice_id => CarbonImmutable::parse($row->requested_at),
            ])
            ->all();
    }
}
