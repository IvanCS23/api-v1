<?php

/**
 * Fase 6.34: extrae del catálogo oficial del SAT (catCFDI_V_4_*.xls) sólo
 * las hojas de impuestos: c_Impuesto, c_TipoFactor y c_TasaOCuota. No toca
 * los CSV de la 6.26 (convert_catalog.php) ni su entrada del manifest:
 * escribe impuesto.csv, tipo_factor.csv y tasa_o_cuota.csv y la llave
 * `tax_catalogs` de manifest.json, con el archivo y el SHA-256 de origen.
 *
 * Uso (desde api-v1):
 *   php -d memory_limit=1G database/data/sat/tools/convert_tax_catalogs.php <ruta.xls> [directorio_salida]
 *
 * Repetible: el mismo .xls produce los mismos archivos (orden del SAT,
 * UTF-8, LF). Los valores de c_TasaOCuota se escriben con 6 decimales.
 * En las filas "Fijo" el SAT pone el valor en la columna "Valor máximo" y
 * deja vacía la de mínimo; se conserva así.
 */

require __DIR__.'/XlsReader.php';

if ($argc < 2) {
    fwrite(STDERR, "Uso: php convert_tax_catalogs.php <catCFDI_V_4_*.xls> [directorio_salida]\n");
    exit(1);
}

$source = $argv[1];
$outDir = rtrim($argv[2] ?? dirname(__DIR__), '/\\');
$reader = new XlsReader($source);

$excelDate = function (mixed $value): string {
    if ($value === null || $value === '' || (float) $value <= 0) {
        return '';
    }

    return (new DateTimeImmutable('1899-12-30'))->modify('+'.(int) $value.' days')->format('Y-m-d');
};

$text = function (mixed $value): string {
    if ($value === null) {
        return '';
    }
    if (is_float($value) || is_int($value)) {
        return floor((float) $value) === (float) $value ? (string) (int) $value : (string) $value;
    }

    return trim(preg_replace('/\s+/u', ' ', $value));
};

$decimal = fn (mixed $value): string => ($value === null || $value === '') ? '' : sprintf('%.6f', (float) $value);

/** Versión del catálogo (fila bajo "Versión CFDI") y fila del encabezado. */
$locate = function (array $rows, string $keyHeader) use ($text, $excelDate): array {
    $version = null;
    foreach ($rows as $index => $cells) {
        if (($cells[0] ?? null) === 'Versión CFDI') {
            $values = $rows[$index + 1] ?? [];
            $version = [
                'version_cfdi' => $text($values[0] ?? null),
                'version_catalogo' => $text($values[1] ?? null),
                'revision' => $text($values[2] ?? null),
                'fecha_publicacion' => $excelDate($values[3] ?? null),
                'vigencia_inicio' => $excelDate($values[4] ?? null),
                'vigencia_fin' => $excelDate($values[5] ?? null),
            ];
        }
        foreach ($cells as $cell) {
            if ($cell === $keyHeader) {
                return [$version, $index];
            }
        }
    }

    fwrite(STDERR, "No se encontró el encabezado {$keyHeader}\n");
    exit(1);
};

$write = function (string $name, array $header, array $lines) use ($outDir): string {
    $file = fopen("{$outDir}/{$name}.csv", 'wb');
    fputcsv($file, $header, ',', '"', '', "\n");
    foreach ($lines as $line) {
        fputcsv($file, $line, ',', '"', '', "\n");
    }
    fclose($file);

    return hash_file('sha256', "{$outDir}/{$name}.csv");
};

$catalogs = [];

// c_Impuesto: clave, descripción, retención, traslado, local o federal.
$rows = $reader->rows('c_Impuesto');
[$version, $headerRow] = $locate($rows, 'c_Impuesto');
$lines = [];
foreach ($rows as $index => $cells) {
    if ($index <= $headerRow || $text($cells[0] ?? null) === '') {
        continue;
    }
    $lines[] = [
        str_pad($text($cells[0]), 3, '0', STR_PAD_LEFT),
        $text($cells[1] ?? null),
        $text($cells[2] ?? null),
        $text($cells[3] ?? null),
        $text($cells[4] ?? null),
        $excelDate($cells[5] ?? null),
        $excelDate($cells[6] ?? null),
    ];
}
$catalogs['impuesto'] = ['sheet' => 'c_Impuesto', 'rows' => count($lines), 'version' => $version,
    'csv_sha256' => $write('impuesto', ['clave', 'descripcion', 'retencion', 'traslado', 'local_federal', 'vigencia_inicio', 'vigencia_fin'], $lines)];

// c_TipoFactor: clave y vigencia.
$rows = $reader->rows('c_TipoFactor');
[$version, $headerRow] = $locate($rows, 'c_TipoFactor');
$lines = [];
foreach ($rows as $index => $cells) {
    if ($index <= $headerRow || $text($cells[0] ?? null) === '') {
        continue;
    }
    $lines[] = [$text($cells[0]), $excelDate($cells[1] ?? null), $excelDate($cells[2] ?? null)];
}
$catalogs['tipo_factor'] = ['sheet' => 'c_TipoFactor', 'rows' => count($lines), 'version' => $version,
    'csv_sha256' => $write('tipo_factor', ['clave', 'vigencia_inicio', 'vigencia_fin'], $lines)];

// c_TasaOCuota: el encabezado ocupa dos filas (Valor mínimo / Valor máximo
// en la segunda). Columnas: 0 Rango o Fijo, 1 mínimo, 2 máximo, 3 impuesto,
// 4 factor, 5 traslado, 6 retención, 7 inicio y 8 fin de vigencia.
$rows = $reader->rows('c_TasaOCuota');
[$version, $headerRow] = $locate($rows, 'c_TasaOCuota');
$lines = [];
foreach ($rows as $index => $cells) {
    if ($index <= $headerRow + 1 || ! in_array($text($cells[0] ?? null), ['Fijo', 'Rango'], true)) {
        continue;
    }
    $lines[] = [
        $text($cells[0]),
        $decimal($cells[1] ?? null),
        $decimal($cells[2] ?? null),
        $text($cells[3] ?? null),
        $text($cells[4] ?? null),
        $text($cells[5] ?? null),
        $text($cells[6] ?? null),
        $excelDate($cells[7] ?? null),
        $excelDate($cells[8] ?? null),
    ];
}
$catalogs['tasa_o_cuota'] = ['sheet' => 'c_TasaOCuota', 'rows' => count($lines), 'version' => $version,
    'csv_sha256' => $write('tasa_o_cuota', ['tipo', 'minimo', 'maximo', 'impuesto', 'factor', 'traslado', 'retencion', 'vigencia_inicio', 'vigencia_fin'], $lines)];

$manifestPath = "{$outDir}/manifest.json";
$manifest = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : [];
$manifest['tax_catalogs'] = [
    'source_file' => basename($source),
    'source_sha256' => hash_file('sha256', $source),
    'catalogs' => $catalogs,
];
file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

foreach ($catalogs as $name => $catalog) {
    echo str_pad($catalog['sheet'], 14).' → '.str_pad("{$name}.csv", 18)." {$catalog['rows']} filas\n";
}
echo "SHA-256 del origen: {$manifest['tax_catalogs']['source_sha256']}\n";
