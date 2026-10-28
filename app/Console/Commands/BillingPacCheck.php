<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Billing\PacCheckService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Fase 6.27 — Verificación de credenciales PAC por empresa. Sin escrituras;
 * la única llamada al PAC es GET /organizations/me con la llave de cada
 * empresa (ver PacCheckService). Nunca imprime llaves, sólo su pista.
 *
 * Sale con código 1 si alguna empresa tiene una verificación `fail`
 * (o `warn`, con --fail-on=warn).
 */
class BillingPacCheck extends Command
{
    /**
     * @var string
     */
    protected $signature = 'billing:pac-check
        {--company=* : Limita a estas empresas (por defecto, todas)}
        {--format=table : Formato de salida: table o json}
        {--fail-on=fail : Sale con código 1 desde este estado (warn, fail, none)}';

    /**
     * @var string
     */
    protected $description = 'Verifica la organización y la llave de Facturapi de cada empresa (solo lectura).';

    public function handle(PacCheckService $checks): int
    {
        $format = (string) $this->option('format');
        $failOn = (string) $this->option('fail-on');

        if (! in_array($format, ['table', 'json'], true) || ! in_array($failOn, ['warn', 'fail', 'none'], true)) {
            $this->error('Opciones inválidas: --format=table|json y --fail-on=warn|fail|none.');

            return self::FAILURE;
        }

        $requested = array_map('intval', (array) $this->option('company'));
        $companyIds = $requested !== []
            ? $requested
            : Company::query()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $now = CarbonImmutable::now();
        $results = $checks->check($companyIds, $now);

        if ($format === 'json') {
            $this->line((string) json_encode([
                'generated_at' => $now->toIso8601String(),
                'read_only' => true,
                'pac_mode' => config('billing.pac_mode'),
                'companies' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->renderTable($results);
        }

        $statuses = array_column($results, 'status');
        $failing = match ($failOn) {
            'warn' => array_intersect($statuses, [PacCheckService::WARN, PacCheckService::FAIL]) !== [],
            'fail' => in_array(PacCheckService::FAIL, $statuses, true),
            default => false,
        };

        return $failing ? self::FAILURE : self::SUCCESS;
    }

    /** @param list<array{id: int, name: string, status: string, checks: list<array{code: string, status: string, detail: string}>}> $results */
    private function renderTable(array $results): void
    {
        if ($results === []) {
            $this->line('No hay empresas que verificar.');

            return;
        }

        foreach ($results as $company) {
            $this->info(sprintf('Empresa %d (%s): %s', $company['id'], $company['name'], mb_strtoupper($company['status'])));
            $this->table(['Verificación', 'Estado', 'Detalle'], array_map(
                fn (array $check): array => [$check['code'], $check['status'], $check['detail']],
                $company['checks'],
            ));
        }
    }
}
