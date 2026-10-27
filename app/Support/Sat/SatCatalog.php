<?php

namespace App\Support\Sat;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fase 6.26: catálogos SAT del CFDI 4.0, con el archivo oficial del Anexo 20
 * como única fuente (ver database/data/sat/README.md).
 *
 * - Catálogos pequeños (régimen fiscal, uso de CFDI con su matriz de
 *   régimen ↔ uso ↔ tipo de persona, forma de pago y objeto de impuesto):
 *   se leen de los CSV versionados en database/data/sat.
 * - Catálogos grandes (c_ClaveProdServ y c_ClaveUnidad): tablas
 *   sat_product_keys y sat_unit_keys, cargadas con sat:import-catalogs.
 *
 * Sólo cuentan las claves vigentes hoy (sin fecha de fin o con fin futuro).
 * Nada aquí consulta al PAC.
 */
final class SatCatalog
{
    /** @var array<string, array<string, array<string, mixed>>> */
    private static array $cache = [];

    public static function directory(): string
    {
        return base_path('database/data/sat');
    }

    /** @return array<string, array{code: string, description: string, physical: bool, moral: bool}> */
    public static function taxRegimes(): array
    {
        return self::load('regimen_fiscal', fn (array $row): array => [
            'code' => $row['clave'],
            'description' => $row['descripcion'],
            'physical' => $row['fisica'] === 'Sí',
            'moral' => $row['moral'] === 'Sí',
        ]);
    }

    /** @return array<string, array{code: string, description: string, physical: bool, moral: bool, regimes: list<string>}> */
    public static function cfdiUses(): array
    {
        return self::load('uso_cfdi', fn (array $row): array => [
            'code' => $row['clave'],
            'description' => $row['descripcion'],
            'physical' => $row['fisica'] === 'Sí',
            'moral' => $row['moral'] === 'Sí',
            'regimes' => $row['regimenes_receptor'] === '' ? [] : explode('|', $row['regimenes_receptor']),
        ]);
    }

    /** @return array<string, array{code: string, description: string}> */
    public static function paymentForms(): array
    {
        return self::load('forma_pago', fn (array $row): array => [
            'code' => $row['clave'],
            'description' => $row['descripcion'],
        ]);
    }

    /** @return array<string, array{code: string, description: string}> */
    public static function taxObjects(): array
    {
        return self::load('objeto_imp', fn (array $row): array => [
            'code' => $row['clave'],
            'description' => $row['descripcion'],
        ]);
    }

    public static function isTaxRegime(?string $code): bool
    {
        return $code !== null && isset(self::taxRegimes()[$code]);
    }

    public static function isCfdiUse(?string $code): bool
    {
        return $code !== null && isset(self::cfdiUses()[$code]);
    }

    public static function isPaymentForm(?string $code): bool
    {
        return $code !== null && isset(self::paymentForms()[$code]);
    }

    public static function isTaxObject(?string $code): bool
    {
        return $code !== null && isset(self::taxObjects()[$code]);
    }

    public static function productKeyExists(?string $code): bool
    {
        return $code !== null && self::existingProductKeys([$code]) !== [];
    }

    public static function unitKeyExists(?string $code): bool
    {
        return $code !== null && self::existingUnitKeys([$code]) !== [];
    }

    /**
     * Claves vigentes de la lista que existen en c_ClaveProdServ, en una sola
     * consulta (para listados y reportes).
     *
     * @param  iterable<?string>  $codes
     * @return array<string, true>
     */
    public static function existingProductKeys(iterable $codes): array
    {
        return self::existing('sat_product_keys', $codes);
    }

    /**
     * @param  iterable<?string>  $codes
     * @return array<string, true>
     */
    public static function existingUnitKeys(iterable $codes): array
    {
        return self::existing('sat_unit_keys', $codes);
    }

    /**
     * @param  iterable<?string>  $codes
     * @return array<string, true>
     */
    private static function existing(string $table, iterable $codes): array
    {
        $codes = collect($codes)->filter(fn ($code): bool => is_string($code) && $code !== '')->unique()->values();

        if ($codes->isEmpty()) {
            return [];
        }

        $today = now()->toDateString();
        $found = [];

        foreach ($codes->chunk(500) as $chunk) {
            DB::table($table)
                ->whereIn('code', $chunk->all())
                ->where(fn ($query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
                ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $today))
                ->pluck('code')
                ->each(function (string $code) use (&$found): void {
                    $found[$code] = true;
                });
        }

        return $found;
    }

    /**
     * @param  callable(array<string, string>): array<string, mixed>  $map
     * @return array<string, array<string, mixed>>
     */
    private static function load(string $name, callable $map): array
    {
        if (isset(self::$cache[$name])) {
            return self::$cache[$name];
        }

        $path = self::directory()."/{$name}.csv";
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("No se encontró el catálogo SAT {$path}");
        }

        $today = now()->toDateString();
        $header = fgetcsv($handle, null, ',', '"', '');
        $entries = [];

        while (($line = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if ($line === [null]) {
                continue;
            }
            $row = array_combine($header, $line);
            if (($row['vigencia_inicio'] ?? '') !== '' && $row['vigencia_inicio'] > $today) {
                continue;
            }
            if (($row['vigencia_fin'] ?? '') !== '' && $row['vigencia_fin'] < $today) {
                continue;
            }
            $entry = $map($row);
            $entries[$entry['code']] = $entry;
        }
        fclose($handle);

        return self::$cache[$name] = $entries;
    }
}
