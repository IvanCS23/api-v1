<?php

namespace App\Console\Commands;

use App\Enums\InvoiceHealthStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Services\Billing\BillingHealthReportService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Diagnóstico de salud del ciclo ERP/PAC (Fase 6.18). Exclusivamente
 * local y read-only: no emite, cancela, reconcilia ni descarga; no hace
 * HTTP y no escribe. Puede ejecutarse en producción.
 */
class BillingHealthCommand extends Command
{
    protected $signature = 'billing:health
        {--company= : Limita el diagnóstico a una empresa}
        {--invoice= : Evalúa una sola Invoice}
        {--status=* : Estados a listar (valid, transient, recoverable, invalid); por omisión los no válidos}
        {--format=table : Formato de salida: table o json}
        {--limit=50 : Máximo de facturas listadas}
        {--fail-on=invalid : Sale con código 1 si algún estado es igual o peor (transient, recoverable, invalid, none)}';

    protected $description = 'Diagnóstico read-only de salud del ciclo de facturación ERP/PAC, sin HTTP ni escrituras.';

    public function handle(BillingHealthReportService $reports): int
    {
        $options = $this->validatedOptions();

        if ($options === null) {
            return self::INVALID;
        }

        $companyIds = $this->resolveCompanyIds($options['company'], $options['invoice']);

        if ($companyIds === null) {
            return self::FAILURE;
        }

        $report = $reports->generate(
            $companyIds,
            CarbonImmutable::now(),
            $options['statuses'],
            $options['limit'],
            $options['invoice'],
        );

        $options['format'] === 'json' ? $this->renderJson($report) : $this->renderTable($report);

        $worst = InvoiceHealthStatus::from($report['worst']);

        return $options['fail_on'] !== null && $worst->isAtLeast($options['fail_on'])
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @return array{company: ?int, invoice: ?int, statuses: list<InvoiceHealthStatus>, format: string, limit: int, fail_on: ?InvoiceHealthStatus}|null
     */
    private function validatedOptions(): ?array
    {
        $company = $this->positiveIntOption('company');
        $invoice = $this->positiveIntOption('invoice');
        $limit = $this->option('limit');
        $format = (string) $this->option('format');
        $failOn = (string) $this->option('fail-on');

        if ($company === false || $invoice === false) {
            $this->error('--company e --invoice deben ser IDs enteros positivos.');

            return null;
        }

        if (! ctype_digit((string) $limit)) {
            $this->error('--limit debe ser un entero mayor o igual a 0.');

            return null;
        }

        if (! in_array($format, ['table', 'json'], true)) {
            $this->error('--format debe ser table o json.');

            return null;
        }

        $failOnStatus = $failOn === 'none' ? null : InvoiceHealthStatus::tryFrom($failOn);

        if ($failOn !== 'none' && ($failOnStatus === null || $failOnStatus === InvoiceHealthStatus::Valid)) {
            $this->error('--fail-on debe ser transient, recoverable, invalid o none.');

            return null;
        }

        $statuses = [];

        foreach ((array) $this->option('status') as $value) {
            $status = InvoiceHealthStatus::tryFrom((string) $value);

            if ($status === null) {
                $this->error("--status no reconoce el valor [{$value}].");

                return null;
            }

            $statuses[] = $status;
        }

        if ($statuses === []) {
            $statuses = [InvoiceHealthStatus::Transient, InvoiceHealthStatus::Recoverable, InvoiceHealthStatus::Invalid];
        }

        return [
            'company' => $company,
            'invoice' => $invoice,
            'statuses' => array_values(array_unique($statuses, SORT_REGULAR)),
            'format' => $format,
            'limit' => (int) $limit,
            'fail_on' => $failOnStatus,
        ];
    }

    private function positiveIntOption(string $name): int|false|null
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        return ctype_digit((string) $value) && (int) $value > 0 ? (int) $value : false;
    }

    /** @return list<int>|null */
    private function resolveCompanyIds(?int $companyId, ?int $invoiceId): ?array
    {
        if ($invoiceId !== null) {
            // Mismo localizador que los demás comandos billing:*: sólo
            // id/company_id; la evaluación posterior corre con CompanyScope.
            $locator = Invoice::withoutGlobalScope(CompanyScope::class)
                ->select(['id', 'company_id'])
                ->find($invoiceId);

            if ($locator === null || ($companyId !== null && (int) $locator->company_id !== $companyId)) {
                $this->error("No existe ninguna Invoice con ID [{$invoiceId}] en el alcance solicitado.");

                return null;
            }

            return [(int) $locator->company_id];
        }

        if ($companyId !== null) {
            if (! Company::query()->whereKey($companyId)->exists()) {
                $this->error("No existe ninguna empresa con ID [{$companyId}].");

                return null;
            }

            return [$companyId];
        }

        return Company::query()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /** @param array<string, mixed> $report */
    private function renderJson(array $report): void
    {
        foreach (['totals', 'by_code', 'by_state', 'informational'] as $map) {
            $report[$map] = (object) $report[$map];
        }

        $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $report */
    private function renderTable(array $report): void
    {
        $this->info('Diagnóstico local de salud: sin escrituras ni llamadas al PAC.');
        $this->line(sprintf('Facturas evaluadas: %d | Peor estado: %s', $report['evaluated'], $report['worst']));

        $this->table(
            ['Salud', 'Facturas'],
            collect($report['totals'])->map(fn (int $count, string $status): array => [$status, $count])->values()->all(),
        );

        if ($report['by_code'] !== []) {
            $this->table(
                ['Hallazgo', 'Facturas'],
                collect($report['by_code'])->map(fn (int $count, string $code): array => [$code, $count])->values()->all(),
            );
        }

        $readyAged = $report['informational']['ready_without_remote_draft_over_limit'];

        if ($readyAged > 0) {
            $this->line(sprintf('Informativo: %d factura(s) ready sin borrador remoto con más de %d h sin cambios.', $readyAged, $report['thresholds']['draft_date_limit_hours']));
        }

        if ($report['invoices'] === []) {
            $this->line('No hay facturas que listar con los estados solicitados.');

            return;
        }

        $this->table(
            ['ID', 'Empresa', 'Folio', 'ERP', 'PAC', 'Emisión', 'Cancelación', 'Salud', 'Hallazgos'],
            array_map(fn (array $invoice): array => [
                $invoice['id'],
                $invoice['company_id'],
                $invoice['folio'],
                $invoice['status'],
                $invoice['pac_status'] ?? '-',
                $invoice['pac_issue_status'] ?? '-',
                $invoice['cancellation_status'] ?? '-',
                $invoice['health'],
                implode(', ', array_map(
                    fn (array $finding): string => sprintf(
                        '%s→%s%s',
                        $finding['code'],
                        $finding['action'],
                        $finding['informational'] ? ' (info)' : '',
                    ),
                    $invoice['findings'],
                )),
            ], $report['invoices']),
        );

        if ($report['truncated']) {
            $this->warn('Listado truncado por --limit; los totales sí incluyen todas las facturas.');
        }
    }
}
