<?php

namespace App\Console\Commands;

use App\Support\Sat\SatCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fase 6.26: carga c_ClaveProdServ y c_ClaveUnidad en sat_product_keys y
 * sat_unit_keys desde los CSV versionados (database/data/sat, generados del
 * archivo oficial del SAT; ver su README).
 *
 * Idempotente: dentro de una transacción reemplaza el contenido de cada
 * tabla por el del CSV, así que correrlo dos veces deja lo mismo y una clave
 * retirada por el SAT desaparece. No hace HTTP. Los catálogos pequeños no se
 * importan: SatCatalog los lee directamente de sus CSV.
 */
class ImportSatCatalogs extends Command
{
    protected $signature = 'sat:import-catalogs
        {--path= : Directorio con clave_prod_serv.csv y clave_unidad.csv (por omisión database/data/sat)}';

    protected $description = 'Carga los catálogos SAT de claves de producto y de unidad desde los CSV versionados.';

    /** @var array<string, array{file: string, columns: array<string, string>}> */
    private const TABLES = [
        'sat_product_keys' => [
            'file' => 'clave_prod_serv.csv',
            'columns' => [
                'code' => 'clave',
                'description' => 'descripcion',
                'similar_words' => 'palabras_similares',
                'valid_from' => 'vigencia_inicio',
                'valid_to' => 'vigencia_fin',
            ],
        ],
        'sat_unit_keys' => [
            'file' => 'clave_unidad.csv',
            'columns' => [
                'code' => 'clave',
                'name' => 'nombre',
                'symbol' => 'simbolo',
                'valid_from' => 'vigencia_inicio',
                'valid_to' => 'vigencia_fin',
            ],
        ],
    ];

    public function handle(): int
    {
        $directory = rtrim((string) ($this->option('path') ?: SatCatalog::directory()), '/\\');
        $rowsByTable = [];

        foreach (self::TABLES as $table => $spec) {
            $rows = $this->readCsv("{$directory}/{$spec['file']}", $spec['columns']);

            if ($rows === null) {
                return self::FAILURE;
            }

            $rowsByTable[$table] = $rows;
        }

        DB::transaction(function () use ($rowsByTable): void {
            foreach ($rowsByTable as $table => $rows) {
                DB::table($table)->delete();

                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
            }
        });

        foreach ($rowsByTable as $table => $rows) {
            $this->info("{$table}: ".count($rows).' claves cargadas.');
        }

        $manifest = json_decode((string) @file_get_contents("{$directory}/manifest.json"), true);
        if (is_array($manifest) && isset($manifest['source_file'])) {
            $this->line("Origen: {$manifest['source_file']} (SHA-256 {$manifest['source_sha256']}).");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $columns  columna de la tabla => encabezado del CSV
     * @return list<array<string, ?string>>|null
     */
    private function readCsv(string $path, array $columns): ?array
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            $this->error("No se encontró {$path}.");

            return null;
        }

        $header = fgetcsv($handle, null, ',', '"', '');
        $missing = array_diff($columns, $header ?: []);

        if ($missing !== []) {
            fclose($handle);
            $this->error("{$path} no tiene las columnas: ".implode(', ', $missing).'.');

            return null;
        }

        $positions = array_map(fn (string $name): int => array_search($name, $header, true), $columns);
        $rows = [];

        while (($line = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if ($line === [null]) {
                continue;
            }

            $row = [];
            foreach ($positions as $column => $position) {
                $value = trim((string) ($line[$position] ?? ''));
                $row[$column] = $value === '' ? null : $value;
            }

            if ($row['code'] === null) {
                continue;
            }

            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }
}
