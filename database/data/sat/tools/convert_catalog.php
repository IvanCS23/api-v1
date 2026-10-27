<?php

/**
 * Convierte el catálogo oficial del SAT (catCFDI_V_4_*.xls, publicado en la
 * página del Anexo 20) a los CSV de database/data/sat. Repetible: el mismo
 * .xls produce siempre los mismos archivos (orden del SAT, UTF-8, LF).
 *
 * Uso (desde api-v1):
 *   php -d memory_limit=1G database/data/sat/tools/convert_catalog.php <ruta.xls> [directorio_salida]
 *
 * No necesita Laravel ni dependencias externas (ver XlsReader.php). Después
 * de convertir, actualiza el README con la URL, la fecha de descarga y el
 * SHA-256 que imprime este script, y carga las tablas con
 * `php artisan sat:import-catalogs`.
 */

require __DIR__.'/XlsReader.php';

if ($argc < 2) {
    fwrite(STDERR, "Uso: php convert_catalog.php <catCFDI_V_4_*.xls> [directorio_salida]\n");
    exit(1);
}

$source = $argv[1];
$outDir = rtrim($argv[2] ?? dirname(__DIR__), '/\\');

$reader = new XlsReader($source);

/**
 * Cada catálogo: hoja, columnas de origen (por encabezado) y transformación
 * de la clave. `pad` completa con ceros a la izquierda las claves que Excel
 * guardó como número (p. ej. 1 → "01", 10101500 → "10101500").
 */
$catalogs = [
    'regimen_fiscal' => [
        'sheet' => 'c_RegimenFiscal',
        'key' => ['c_RegimenFiscal', 3],
        'columns' => [
            'descripcion' => 'Descripción',
            'fisica' => 'Física',
            'moral' => 'Moral',
            'vigencia_inicio' => 'Fecha de inicio de vigencia',
            'vigencia_fin' => 'Fecha de fin de vigencia',
        ],
    ],
    'uso_cfdi' => [
        'sheet' => 'c_UsoCFDI',
        'key' => ['c_UsoCFDI', 0],
        // El encabezado ocupa dos filas: "Aplica para tipo persona" agrupa
        // Física/Moral en la fila siguiente (mismas columnas 2 y 3).
        'columns' => [
            'descripcion' => 'Descripción',
            'fisica' => 2,
            'moral' => 3,
            'vigencia_inicio' => 'Fecha inicio de vigencia',
            'vigencia_fin' => 'Fecha fin de vigencia',
            'regimenes_receptor' => 'Régimen Fiscal Receptor',
        ],
    ],
    'forma_pago' => [
        'sheet' => 'c_FormaPago',
        'key' => ['c_FormaPago', 2],
        'columns' => [
            'descripcion' => 'Descripción',
            'bancarizado' => 'Bancarizado',
            'vigencia_inicio' => 'Fecha inicio de vigencia',
            'vigencia_fin' => 'Fecha fin de vigencia',
        ],
    ],
    'objeto_imp' => [
        'sheet' => 'c_ObjetoImp',
        'key' => ['c_ObjetoImp', 2],
        'columns' => [
            'descripcion' => 'Descripción',
            'vigencia_inicio' => 'Fecha inicio de vigencia',
            'vigencia_fin' => 'Fecha fin de vigencia',
        ],
    ],
    'clave_prod_serv' => [
        'sheet' => 'c_ClaveProdServ',
        'key' => ['c_ClaveProdServ', 8],
        'columns' => [
            'descripcion' => 'Descripción',
            'vigencia_inicio' => 'FechaInicioVigencia',
            'vigencia_fin' => 'FechaFinVigencia',
            'palabras_similares' => 'Palabras similares',
        ],
    ],
    'clave_unidad' => [
        'sheet' => 'c_ClaveUnidad',
        'key' => ['c_ClaveUnidad', 0],
        'columns' => [
            'nombre' => 'Nombre',
            'simbolo' => 'Símbolo',
            'vigencia_inicio' => 'Fecha de inicio de vigencia',
            'vigencia_fin' => 'Fecha de fin de vigencia',
        ],
    ],
];

$dateColumns = ['vigencia_inicio', 'vigencia_fin'];

/** Excel (sistema 1900) → Y-m-d. */
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
    if (is_float($value)) {
        return floor($value) === $value ? (string) (int) $value : (string) $value;
    }

    // Normaliza espacios repetidos y saltos de línea del original.
    return trim(preg_replace('/\s+/u', ' ', $value));
};

$manifest = [
    'source_file' => basename($source),
    'source_sha256' => hash_file('sha256', $source),
    'catalogs' => [],
];

foreach ($catalogs as $name => $spec) {
    $rows = $reader->rows($spec['sheet']);
    [$keyHeader, $pad] = $spec['key'];

    // Versión del catálogo: la fila de valores bajo "Versión CFDI".
    $version = null;
    $headerRow = null;
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
        if (($cells[0] ?? null) === $keyHeader) {
            $headerRow = $index;
            break;
        }
    }

    if ($headerRow === null) {
        fwrite(STDERR, "No se encontró el encabezado {$keyHeader} en {$spec['sheet']}\n");
        exit(1);
    }

    $columnIndex = [];
    foreach ($spec['columns'] as $column => $header) {
        if (is_int($header)) {
            $columnIndex[$column] = $header;

            continue;
        }
        $found = array_search($header, array_map($text, $rows[$headerRow]), true);
        if ($found === false) {
            fwrite(STDERR, "No se encontró la columna '{$header}' en {$spec['sheet']}\n");
            exit(1);
        }
        $columnIndex[$column] = $found;
    }

    $file = fopen("{$outDir}/{$name}.csv", 'wb');
    fputcsv($file, ['clave', ...array_keys($spec['columns'])], ',', '"', '', "\n");

    $count = 0;
    foreach ($rows as $index => $cells) {
        if ($index <= $headerRow || ! isset($cells[0]) || $text($cells[0]) === '') {
            continue;
        }

        $key = $text($cells[0]);
        if ($pad > 0 && ctype_digit($key)) {
            $key = str_pad($key, $pad, '0', STR_PAD_LEFT);
        }

        $line = [$key];
        foreach ($columnIndex as $column => $col) {
            $value = $cells[$col] ?? null;
            if (in_array($column, $dateColumns, true)) {
                $line[] = $excelDate($value);
            } elseif ($column === 'regimenes_receptor') {
                // "601, 603,606" → "601|603|606"
                $codes = array_filter(array_map('trim', explode(',', $text($value))));
                $line[] = implode('|', $codes);
            } else {
                $line[] = $text($value);
            }
        }

        fputcsv($file, $line, ',', '"', '', "\n");
        $count++;
    }
    fclose($file);

    $manifest['catalogs'][$name] = [
        'sheet' => $spec['sheet'],
        'rows' => $count,
        'version' => $version,
        'csv_sha256' => hash_file('sha256', "{$outDir}/{$name}.csv"),
    ];

    echo str_pad($spec['sheet'], 18).' → '.str_pad("{$name}.csv", 22)." {$count} filas\n";
}

file_put_contents(
    "{$outDir}/manifest.json",
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
);

echo "SHA-256 del origen: {$manifest['source_sha256']}\n";
