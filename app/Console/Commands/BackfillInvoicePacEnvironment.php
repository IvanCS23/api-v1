<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\FacturapiOrganization;
use App\Models\Invoice;
use App\Support\Billing\PacEnvironment;
use App\Support\Billing\PacEnvironmentAccess;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fase 6.31 — Llena `invoices.pac_environment` de las facturas anteriores
 * a la columna que ya tienen operaciones del PAC. Sin HTTP.
 *
 * El ambiente se deduce de `livemode` en las respuestas guardadas
 * (pac_response / pac_draft_response, cifradas): false → test, true →
 * live. Respuestas en conflicto se reportan y se dejan vacías.
 *
 * Una factura con operaciones pero sin ninguna respuesta guardada (p. ej.
 * una reserva de borrador sin id remoto) no tiene evidencia: se reporta.
 * Con --assume-test se fija en test, pero sólo si en todo el alcance
 * ninguna respuesta guardada trae livemode=true y ninguna empresa tuvo
 * llave Live ni ambiente Live; si no, aborta sin escribir nada. Lo que
 * asumió queda listado.
 *
 * Las facturas sin operaciones del PAC no se tocan: su primera reserva
 * fija el ambiente. Sin --apply sólo muestra lo que haría. Con --apply
 * escribe sólo esa columna, sólo donde sigue vacía y sin tocar
 * updated_at.
 */
class BackfillInvoicePacEnvironment extends Command
{
    /**
     * @var string
     */
    protected $signature = 'billing:backfill-pac-environment
        {--company=* : Limita a estas empresas (por defecto, todas)}
        {--assume-test : Fija en test las facturas sin evidencia (aborta si hay rastros de Live)}
        {--apply : Escribe la columna; sin esta opción sólo muestra lo que haría}';

    /**
     * @var string
     */
    protected $description = 'Llena el ambiente del PAC (test/live) de cada factura desde sus respuestas guardadas (sin HTTP).';

    public function handle(CurrentTenant $tenant): int
    {
        $apply = (bool) $this->option('apply');
        $assumeTest = (bool) $this->option('assume-test');
        $companyIds = array_map('intval', (array) $this->option('company'));
        $companies = Company::query()
            ->when($companyIds !== [], fn ($query) => $query->whereIn('id', $companyIds))
            ->orderBy('id')
            ->get();

        $this->info('Ambiente del PAC por factura'.($apply ? '' : ' — dry-run').($assumeTest ? ' (con --assume-test)' : ''));

        $previousTenant = $tenant->id();
        $plans = [];

        try {
            foreach ($companies as $company) {
                $tenant->set($company->id);
                $plans[$company->id] = ['company' => $company, ...$this->planCompany()];
            }

            if ($assumeTest && ($reason = $this->liveTraces($plans)) !== null) {
                $this->error("--assume-test abortado: {$reason}. No se escribió nada.");

                return self::FAILURE;
            }

            $totals = ['deduced' => 0, 'assumed' => 0, 'skipped' => 0];

            foreach ($plans as $companyId => $plan) {
                $tenant->set($companyId);
                $this->report($plan, $assumeTest, $apply, $totals);
            }
        } finally {
            $previousTenant === null ? $tenant->clear() : $tenant->set($previousTenant);
        }

        $this->line(sprintf(
            '%s por respuesta guardada: %d. %s: %d. Sin evidencia o en conflicto (se dejan vacías): %d.',
            $apply ? 'Llenadas' : 'Por llenar',
            $totals['deduced'],
            $apply ? 'Asumidas test' : 'Se asumirían test',
            $totals['assumed'],
            $totals['skipped'],
        ));

        if (! $apply) {
            $this->warn('Dry-run: no se guardó nada. Repite con --apply para escribir.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{deduced: list<array{Invoice, string}>, unknown: list<Invoice>, conflict: list<Invoice>, live_responses: int, live_organization: bool}
     */
    private function planCompany(): array
    {
        $plan = ['deduced' => [], 'unknown' => [], 'conflict' => [], 'live_responses' => 0, 'live_organization' => false];

        $organization = FacturapiOrganization::query()->first();
        $plan['live_organization'] = $organization !== null
            && (PacEnvironment::forOrganization($organization) === PacEnvironment::LIVE || $organization->live_key_set_at !== null);

        Invoice::withTrashed()->orderBy('id')->each(function (Invoice $invoice) use (&$plan): void {
            $modes = $this->livemodes($invoice);

            if (in_array(true, $modes, true)) {
                $plan['live_responses']++;
            }

            if (filled($invoice->pac_environment) || ! PacEnvironmentAccess::hasPacState($invoice)) {
                return;
            }

            $distinct = array_values(array_unique($modes, SORT_REGULAR));

            match (true) {
                count($distinct) > 1 => $plan['conflict'][] = $invoice,
                $distinct === [] => $plan['unknown'][] = $invoice,
                default => $plan['deduced'][] = [$invoice, $distinct[0] ? PacEnvironment::LIVE : PacEnvironment::TEST],
            };
        });

        return $plan;
    }

    /** @return list<bool> */
    private function livemodes(Invoice $invoice): array
    {
        $modes = [];

        foreach (['pac_response', 'pac_draft_response'] as $column) {
            try {
                $response = $invoice->{$column};
            } catch (\Throwable) {
                $response = null;
            }

            if (is_array($response) && is_bool($response['livemode'] ?? null)) {
                $modes[] = $response['livemode'];
            }
        }

        return $modes;
    }

    /** @param array<int, array<string, mixed>> $plans */
    private function liveTraces(array $plans): ?string
    {
        foreach ($plans as $companyId => $plan) {
            if ($plan['live_responses'] > 0) {
                return "la empresa {$companyId} tiene {$plan['live_responses']} factura(s) con respuestas livemode=true";
            }

            if ($plan['live_organization']) {
                return "la empresa {$companyId} tiene o tuvo llave o ambiente Live";
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array{deduced: int, assumed: int, skipped: int}  $totals
     */
    private function report(array $plan, bool $assumeTest, bool $apply, array &$totals): void
    {
        $rows = [];

        foreach ($plan['deduced'] as [$invoice, $environment]) {
            $this->write($invoice, $environment, $apply);
            $totals['deduced']++;
            $rows[] = [$invoice->id, $invoice->folio, $environment, 'respuesta guardada', $apply ? 'llenada' : 'se llenaría'];
        }

        foreach ($plan['unknown'] as $invoice) {
            if ($assumeTest) {
                $this->write($invoice, PacEnvironment::TEST, $apply);
                $totals['assumed']++;
                $rows[] = [$invoice->id, $invoice->folio, PacEnvironment::TEST, 'ASUMIDO (--assume-test)', $apply ? 'llenada' : 'se llenaría'];

                continue;
            }

            $totals['skipped']++;
            $rows[] = [$invoice->id, $invoice->folio, '—', 'sin respuesta guardada', 'se deja vacía (bloqueada)'];
        }

        foreach ($plan['conflict'] as $invoice) {
            $totals['skipped']++;
            $rows[] = [$invoice->id, $invoice->folio, '—', 'respuestas en conflicto', 'se deja vacía (bloqueada)'];
        }

        if ($rows === []) {
            return;
        }

        $company = $plan['company'];
        $this->line(sprintf('Empresa %d (%s):', $company->id, $company->trade_name));
        $this->table(['Factura', 'Folio', 'Ambiente', 'Fuente', 'Acción'], $rows);
    }

    private function write(Invoice $invoice, string $environment, bool $apply): void
    {
        if ($apply) {
            DB::table('invoices')->where('id', $invoice->id)->whereNull('pac_environment')->update(['pac_environment' => $environment]);
        }
    }
}
