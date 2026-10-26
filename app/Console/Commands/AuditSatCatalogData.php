<?php

namespace App\Console\Commands;

use App\Services\Sat\SatCatalogAuditService;
use Illuminate\Console\Command;

/**
 * Fase 6.26: reporte de clientes y productos guardados que ya no cumplen la
 * validación SAT de captura. Exclusivamente de lectura: no repara nada, no
 * escribe y no hace HTTP. Puede ejecutarse en producción.
 *
 * Al editar uno de esos registros, cambiar cualquiera de sus datos fiscales
 * obliga a corregir el conjunto (ver ValidatesClientFiscalData y
 * ValidatesProductFiscalData), y una venta que los use no pasa el readiness.
 */
class AuditSatCatalogData extends Command
{
    protected $signature = 'sat:audit-catalog-data
        {--company= : Limita el reporte a una empresa}
        {--format=table : Formato de salida: table o json}
        {--fail-on-issues : Sale con código 1 si encuentra registros inválidos}';

    protected $description = 'Reporte de solo lectura de clientes y productos que no cumplen los catálogos del SAT.';

    public function handle(SatCatalogAuditService $audit): int
    {
        $company = $this->option('company');
        $format = (string) $this->option('format');

        if ($company !== null && (! ctype_digit((string) $company) || (int) $company < 1)) {
            $this->error('--company debe ser un ID entero positivo.');

            return self::INVALID;
        }

        if (! in_array($format, ['table', 'json'], true)) {
            $this->error('--format debe ser table o json.');

            return self::INVALID;
        }

        $report = $audit->report($company === null ? null : (int) $company);

        $format === 'json' ? $this->renderJson($report) : $this->renderTable($report);

        $invalid = $report['clients']['invalid'] + $report['products']['invalid'];

        return $this->option('fail-on-issues') && $invalid > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @param array<string, mixed> $report */
    private function renderJson(array $report): void
    {
        $report['by_code'] = (object) $report['by_code'];

        $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $report */
    private function renderTable(array $report): void
    {
        $this->info('Reporte SAT de catálogos: sólo lectura, sin correcciones ni llamadas al PAC.');
        $this->line(sprintf(
            'Clientes: %d de %d con problemas | Productos: %d de %d con problemas',
            $report['clients']['invalid'],
            $report['clients']['total'],
            $report['products']['invalid'],
            $report['products']['total'],
        ));

        if ($report['clients']['rows'] !== []) {
            $this->table(
                ['Cliente', 'Empresa', 'Códigos', 'Campos'],
                array_map(fn (array $row): array => [$row['id'], $row['company_id'], implode(', ', $row['codes']), implode(', ', $row['fields'])], $report['clients']['rows']),
            );
        }

        if ($report['products']['rows'] !== []) {
            $this->table(
                ['Producto', 'Empresa', 'Clave', 'Unidad', 'Objeto', 'Tasa', 'Códigos'],
                array_map(fn (array $row): array => [
                    $row['id'],
                    $row['company_id'],
                    $row['values']['clave_producto'] ?? '',
                    $row['values']['clave_unidad'] ?? '',
                    $row['values']['objeto_imp'] ?? '',
                    $row['values']['tax_rate_id'] ?? '—',
                    implode(', ', $row['codes']),
                ], $report['products']['rows']),
            );
        }

        if ($report['by_code'] !== []) {
            $this->table(
                ['Código', 'Registros'],
                collect($report['by_code'])->map(fn (int $count, string $code): array => [$code, $count])->values()->all(),
            );
        }
    }
}
