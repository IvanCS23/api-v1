<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Fase 6.26: muestra real de c_ClaveProdServ y c_ClaveUnidad (copiada de
 * database/data/sat) que se carga antes de cada prueba Feature (ver
 * tests/Pest.php). Importar las 52 mil claves en cada prueba sería lento;
 * los catálogos pequeños no la necesitan porque SatCatalog los lee del CSV.
 */
final class SatCatalogFixture
{
    public const PRODUCT_KEY = '43232408';

    public const SERVICE_KEY = '84111506';

    public const UNIT_KEY = 'H87';

    public const SERVICE_UNIT_KEY = 'E48';

    /** @var array<string, string> */
    public const PRODUCT_KEYS = [
        '01010101' => 'No existe en el catálogo',
        '43211503' => 'Computadores notebook',
        '43232408' => 'Software de desarrollo de plataformas web',
        '50202306' => 'Refrescos',
        '80141600' => 'Actividades de ventas y promoción de negocios',
        '81111500' => 'Ingeniería de software o hardware',
        '84111506' => 'Servicios de facturación',
        '90101501' => 'Restaurantes',
    ];

    /** @var array<string, string> */
    public const UNIT_KEYS = [
        'ACT' => 'Actividad',
        'E48' => 'Unidad de servicio',
        'H87' => 'Pieza',
        'KGM' => 'Kilogramo',
        'XUN' => 'Unidad',
    ];

    public static function seed(): void
    {
        DB::table('sat_product_keys')->insert(array_map(
            fn (string $code, string $description): array => ['code' => $code, 'description' => $description, 'valid_from' => '2022-01-01'],
            array_keys(self::PRODUCT_KEYS),
            self::PRODUCT_KEYS,
        ));

        DB::table('sat_unit_keys')->insert(array_map(
            fn (string $code, string $name): array => ['code' => $code, 'name' => $name, 'valid_from' => '2022-01-01'],
            array_keys(self::UNIT_KEYS),
            self::UNIT_KEYS,
        ));
    }
}
