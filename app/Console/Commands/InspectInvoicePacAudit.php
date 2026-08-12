<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Console\Command;

/**
 * Inspeccion exclusivamente local de la bitacora PAC. No hace HTTP.
 */
class InspectInvoicePacAudit extends Command
{
    protected $signature = 'billing:pac-audit
        {invoice : ID de la Invoice cuya bitacora PAC se mostrara}';

    protected $description = 'Muestra la bitacora fiscal PAC sanitizada de una Invoice, sin llamadas HTTP.';

    public function handle(): int
    {
        $invoiceId = (int) $this->argument('invoice');
        $locator = Invoice::withoutGlobalScope(CompanyScope::class)
            ->select(['id', 'company_id'])
            ->find($invoiceId);

        if ($locator === null) {
            $this->error("No existe ninguna Invoice con ID [{$invoiceId}].");

            return self::FAILURE;
        }

        app(CurrentTenant::class)->set($locator->company_id);

        try {
            $invoice = Invoice::findOrFail($invoiceId);
            $events = $invoice->pacEvents()->orderBy('occurred_at')->orderBy('id')->get();

            if ($events->isEmpty()) {
                $this->info('La Invoice no tiene eventos PAC registrados desde Fase 6.7.');

                return self::SUCCESS;
            }

            $this->table(
                ['Timestamp', 'Event type', 'PAC status', 'Cancellation', 'Issue status', 'PAC code', 'Context sanitizado'],
                $events->map(fn ($event): array => [
                    $event->occurred_at?->toIso8601String(),
                    $event->event_type->value,
                    $event->pac_status ?? '-',
                    $event->cancellation_status ?? '-',
                    $event->pac_issue_status ?? '-',
                    $event->pac_code ?? '-',
                    $this->summarizeContext($event->context),
                ])->all(),
            );

            return self::SUCCESS;
        } finally {
            app(CurrentTenant::class)->clear();
        }
    }

    /** @param array<string, mixed>|null $context */
    private function summarizeContext(?array $context): string
    {
        if ($context === null || $context === []) {
            return '-';
        }

        $summary = [];

        foreach ($context as $key => $value) {
            $summary[$key] = is_array($value) ? '[nested]' : $value;
        }

        $encoded = json_encode($summary, JSON_UNESCAPED_SLASHES) ?: '[unavailable]';
        $apiKey = (string) config('services.facturapi.test_key');

        if ($apiKey !== '') {
            $encoded = str_replace($apiKey, '[redacted]', $encoded);
        }

        $encoded = preg_replace('/\bBearer\s+[^\s"}]+/i', '[redacted]', $encoded) ?? '[redacted]';
        $encoded = preg_replace('/\bsk_(?:live|test)_[A-Za-z0-9._-]+\b/i', '[redacted]', $encoded) ?? '[redacted]';

        return mb_substr($encoded, 0, 500);
    }
}
