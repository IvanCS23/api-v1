<?php

use App\Enums\TaxFactorType;
use App\Enums\TaxType;
use App\Models\TaxRate;
use App\Services\Sales\LineItemCalculator;
use App\Services\Taxes\DecimalMath;
use App\Services\Taxes\LineTaxCalculator;
use App\Services\Taxes\TaxSpec;

/**
 * Fase 6.34 (3): cálculo exacto (bcmath) con varios impuestos por línea.
 * Casos de referencia calculados a mano; se compararán contra Facturapi
 * TEST en el paso 7.
 */
function phase634Spec(string $tax, string $kind, string $factor, ?string $rate = null): TaxSpec
{
    return TaxSpec::fromArray(['tax' => $tax, 'kind' => $kind, 'factor' => $factor, 'rate' => $rate]);
}

function phase634Calc(mixed $quantity, mixed $price, mixed $discount, array $specs): array
{
    return (new LineTaxCalculator)->calculate($quantity, $price, $discount, $specs);
}

test('IVA 16 % con descuento', function () {
    $r = phase634Calc(2, '100', '10', [phase634Spec('002', 'traslado', 'tasa', '0.16')]);

    expect($r)->toMatchArray(['subtotal' => '200.00', 'discount' => '10.00', 'base' => '190.00', 'tax_total' => '30.40', 'taxes_transferred' => '30.40', 'taxes_withheld' => '0.00', 'total' => '220.40'])
        ->and($r['taxes'][0])->toMatchArray(['tax' => '002', 'kind' => 'traslado', 'factor' => 'tasa', 'rate' => '0.160000', 'base' => '190.000000', 'amount' => '30.400000', 'amount_rounded' => '30.40']);
});

test('IVA exento: sin tasa ni importe', function () {
    $r = phase634Calc(1, '250', '0', [phase634Spec('002', 'traslado', 'exento')]);

    expect($r['total'])->toBe('250.00')
        ->and($r['taxes_transferred'])->toBe('0.00')
        ->and($r['taxes'][0])->toMatchArray(['factor' => 'exento', 'rate' => null, 'base' => '250.000000', 'amount' => null, 'amount_rounded' => '0.00']);
});

test('IEPS 8 % desglosado se suma a la base del IVA (sum_before_taxes)', function () {
    $r = phase634Calc(1, '100', '0', [phase634Spec('002', 'traslado', 'tasa', '0.16'), phase634Spec('003', 'traslado', 'tasa', '0.08')]);

    // El orden de salida respeta el de entrada; el cálculo hace primero el IEPS.
    expect($r['taxes'][0])->toMatchArray(['tax' => '002', 'base' => '108.000000', 'amount' => '17.280000'])
        ->and($r['taxes'][1])->toMatchArray(['tax' => '003', 'ieps_mode' => 'sum_before_taxes', 'base' => '100.000000', 'amount' => '8.000000'])
        ->and($r['taxes_transferred'])->toBe('25.28')
        ->and($r['total'])->toBe('125.28');
});

test('IEPS por cuota: la base es la cantidad', function () {
    $r = phase634Calc(3, '10', '0', [phase634Spec('003', 'traslado', 'cuota', '1.5'), phase634Spec('002', 'traslado', 'tasa', '0.16')]);

    expect($r['taxes'][0])->toMatchArray(['factor' => 'cuota', 'rate' => '1.500000', 'base' => '3.000000', 'amount' => '4.500000'])
        ->and($r['taxes'][1])->toMatchArray(['base' => '34.500000', 'amount' => '5.520000'])
        ->and($r['total'])->toBe('40.02');
});

test('honorarios: IVA 16 %, IVA retenido 2/3 e ISR retenido 10 %', function () {
    $r = phase634Calc(1, '1000', '0', [
        phase634Spec('002', 'traslado', 'tasa', '0.16'),
        phase634Spec('002', 'retencion', 'tasa', '0.106667'),
        phase634Spec('001', 'retencion', 'tasa', '0.10'),
    ]);

    expect($r['taxes'][1])->toMatchArray(['kind' => 'retencion', 'amount' => '106.667000', 'amount_rounded' => '106.67'])
        ->and($r['taxes'][2])->toMatchArray(['amount' => '100.000000', 'amount_rounded' => '100.00'])
        ->and($r)->toMatchArray(['tax_total' => '160.00', 'taxes_transferred' => '160.00', 'taxes_withheld' => '206.67', 'total' => '953.33']);
});

test('RESICO: ISR retenido 1.25 %', function () {
    $r = phase634Calc(1, '1000', '0', [phase634Spec('002', 'traslado', 'tasa', '0.16'), phase634Spec('001', 'retencion', 'tasa', '0.0125')]);

    expect($r)->toMatchArray(['taxes_withheld' => '12.50', 'total' => '1147.50']);
});

test('redondeo: la mitad se aleja del cero y se conserva el importe exacto', function () {
    $r = phase634Calc('1.255', '10.10', '0', [phase634Spec('002', 'traslado', 'tasa', '0.16')]);

    // 1.255 × 10.10 = 12.6755 → 12.68; 12.68 × 0.16 = 2.0288 → 2.03.
    expect($r['subtotal'])->toBe('12.68')
        ->and($r['taxes'][0])->toMatchArray(['amount' => '2.028800', 'amount_rounded' => '2.03'])
        ->and(DecimalMath::round('0.125', 2))->toBe('0.13')
        ->and(DecimalMath::round('-0.125', 2))->toBe('-0.13')
        ->and(DecimalMath::round('2.0000005', 6))->toBe('2.000001');
});

test('un descuento mayor que el importe deja la base en cero', function () {
    $r = phase634Calc(1, '50', '80', [phase634Spec('002', 'traslado', 'tasa', '0.16')]);

    expect($r)->toMatchArray(['base' => '0.00', 'taxes_transferred' => '0.00', 'total' => '0.00']);
});

test('corrige el error latente: una retención ya no se suma como traslado', function () {
    $retention = new TaxRate(['code' => '001', 'name' => 'ISR Retenido 10%', 'rate' => 0.10, 'tax_type' => TaxType::Retencion, 'factor_type' => TaxFactorType::Tasa]);

    $r = (new LineItemCalculator)->calculateItem(1, 100, 0, $retention);

    // Antes: tax_total 10 y total 110 (sumada como traslado).
    expect($r)->toBe(['subtotal' => 100.0, 'discount' => 0.0, 'tax_total' => 0.0, 'taxes_transferred' => 0.0, 'taxes_withheld' => 10.0, 'total' => 90.0]);
});

test('el cálculo de siempre con IVA 16 % no cambia', function () {
    $iva = new TaxRate(['code' => '002', 'name' => 'IVA 16%', 'rate' => 0.16, 'tax_type' => TaxType::Traslado, 'factor_type' => TaxFactorType::Tasa]);

    expect((new LineItemCalculator)->calculateItem(3, 33.33, 0, $iva))
        ->toBe(['subtotal' => 99.99, 'discount' => 0.0, 'tax_total' => 16.0, 'taxes_transferred' => 16.0, 'taxes_withheld' => 0.0, 'total' => 115.99]);
});
