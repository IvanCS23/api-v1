<?php

use App\Support\Sat\SatCatalog;

/**
 * Fase 6.34 (1): c_Impuesto, c_TipoFactor y c_TasaOCuota desde el catálogo
 * oficial del SAT (catCFDI_V_4_20261106.xls, ver database/data/sat).
 */
test('c_Impuesto: ISR sólo retención; IVA e IEPS traslado y retención', function () {
    $taxes = SatCatalog::taxes();

    expect(array_keys($taxes))->toBe(['001', '002', '003'])
        ->and($taxes['001'])->toMatchArray(['description' => 'ISR', 'transfer' => false, 'withholding' => true])
        ->and($taxes['002'])->toMatchArray(['description' => 'IVA', 'transfer' => true, 'withholding' => true])
        ->and($taxes['003'])->toMatchArray(['description' => 'IEPS', 'transfer' => true, 'withholding' => true]);
});

test('c_TipoFactor: Tasa, Cuota y Exento', function () {
    expect(array_keys(SatCatalog::taxFactors()))->toBe(['Tasa', 'Cuota', 'Exento']);
});

test('c_TasaOCuota: valores fijos y rangos del archivo del SAT', function () {
    $rates = collect(SatCatalog::taxRates());
    $find = fn (string $tax, string $factor, bool $withholding, string $value) => $rates->first(
        fn (array $r) => $r['tax'] === $tax && $r['factor'] === $factor && ($withholding ? $r['withholding'] : $r['transfer'])
            && ! $r['border_credit'] && bccomp($r['min'], $value, 6) <= 0 && bccomp($value, $r['max'], 6) <= 0
    );

    expect($rates)->toHaveCount(19)
        // IVA traslado: 0 y 16 % fijos; retención: rango de 0 a 16 %.
        ->and($find('002', 'Tasa', false, '0.160000'))->not->toBeNull()
        ->and($find('002', 'Tasa', false, '0.000000'))->not->toBeNull()
        ->and($find('002', 'Tasa', false, '0.100000'))->toBeNull()
        ->and($find('002', 'Tasa', true, '0.106667'))->not->toBeNull()
        ->and($find('002', 'Tasa', true, '0.170000'))->toBeNull()
        // ISR sólo retención, rango de 0 a 35 %.
        ->and($find('001', 'Tasa', true, '0.012500'))->not->toBeNull()
        ->and($find('001', 'Tasa', false, '0.100000'))->toBeNull()
        // IEPS: tasas fijas y cuota en rango de 0 a 72.1605.
        ->and($find('003', 'Tasa', false, '0.080000'))->not->toBeNull()
        ->and($find('003', 'Tasa', false, '0.100000'))->toBeNull()
        ->and($find('003', 'Cuota', false, '72.160500'))->not->toBeNull()
        ->and($find('003', 'Cuota', false, '72.160600'))->toBeNull()
        // 3 % y 0 % de IEPS no admiten retención.
        ->and($find('003', 'Tasa', true, '0.030000'))->toBeNull();
});

test('el 8 % de IVA fronterizo queda marcado y separado del IVA general', function () {
    $border = collect(SatCatalog::taxRates())->where('border_credit', true);

    expect($border)->toHaveCount(1)
        ->and($border->first())->toMatchArray(['tax' => '002', 'factor' => 'Tasa', 'min' => '0.080000', 'max' => '0.080000', 'transfer' => true]);
});

test('el manifest registra el origen y el SHA-256 de los CSV de impuestos', function () {
    $manifest = json_decode((string) file_get_contents(base_path('database/data/sat/manifest.json')), true);
    $tax = $manifest['tax_catalogs'];

    expect($tax['source_file'])->toBe('catCFDI_V_4_20261106.xls')
        ->and($tax['source_sha256'])->toBe('6249e37275ce164a1c4ac44e726dcd60858dac5c5604ff6c9ab06005a9199e1a')
        // Los catálogos de la 6.26 conservan su origen.
        ->and($manifest['source_file'])->toBe('catCFDI_V_4_20261015.xls');

    foreach (['impuesto', 'tipo_factor', 'tasa_o_cuota'] as $name) {
        expect(hash_file('sha256', base_path("database/data/sat/{$name}.csv")))->toBe($tax['catalogs'][$name]['csv_sha256'], $name);
    }
});
