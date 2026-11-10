<?php

use App\Enums\TaxFactorType;
use App\Enums\TaxType;
use App\Services\Taxes\TaxRuleValidator;
use App\Services\Taxes\TaxSpec;
use App\Support\Taxes\LegalTaxRules;

/**
 * Fase 6.34 (4): reglas que el SAT rechaza al timbrar (errores), lista
 * cerrada de retenciones (D3) y reglas legales como avisos (D2).
 */
function phase634Rule(string $tax, string $kind, string $factor, ?string $rate = null, ?string $iepsMode = null): TaxSpec
{
    return TaxSpec::fromArray(['tax' => $tax, 'kind' => $kind, 'factor' => $factor, 'rate' => $rate, 'ieps_mode' => $iepsMode]);
}

function phase634Codes(array $specs, ?string $object = null): array
{
    return array_column((new TaxRuleValidator)->issues($specs, $object), 'code');
}

test('combinaciones válidas no dan errores', function (array $specs, string $object) {
    expect(phase634Codes($specs, $object))->toBe([]);
})->with([
    'IVA 16 %' => [[phase634Rule('002', 'traslado', 'tasa', '0.16')], '02'],
    'IVA 0 %' => [[phase634Rule('002', 'traslado', 'tasa', '0')], '02'],
    'IVA exento' => [[phase634Rule('002', 'traslado', 'exento')], '02'],
    'honorarios' => [[phase634Rule('002', 'traslado', 'tasa', '0.16'), phase634Rule('002', 'retencion', 'tasa', '0.106667'), phase634Rule('001', 'retencion', 'tasa', '0.10')], '02'],
    'RESICO' => [[phase634Rule('002', 'traslado', 'tasa', '0.16'), phase634Rule('001', 'retencion', 'tasa', '0.0125')], '02'],
    'IEPS 8 % + IVA' => [[phase634Rule('003', 'traslado', 'tasa', '0.08'), phase634Rule('002', 'traslado', 'tasa', '0.16')], '02'],
    'IEPS tasa y cuota' => [[phase634Rule('003', 'traslado', 'tasa', '0.08'), phase634Rule('003', 'traslado', 'cuota', '1.5'), phase634Rule('002', 'traslado', 'tasa', '0.16')], '02'],
    'objeto 07 con IEPS' => [[phase634Rule('003', 'traslado', 'tasa', '0.08')], '07'],
    'objeto 01 sin impuestos' => [[], '01'],
]);

test('reglas del SAT: catálogo, factor y exento', function (array $specs, string $code) {
    expect(phase634Codes($specs))->toContain($code);
})->with([
    'impuesto inexistente' => [[phase634Rule('004', 'traslado', 'tasa', '0.16')], 'TAX_UNKNOWN'],
    'ISR trasladado' => [[phase634Rule('001', 'traslado', 'tasa', '0.10')], 'TAX_KIND_NOT_ALLOWED'],
    'IVA 10 % trasladado' => [[phase634Rule('002', 'traslado', 'tasa', '0.10')], 'TAX_RATE_NOT_IN_CATALOG'],
    'IVA retenido 17 %' => [[phase634Rule('002', 'retencion', 'tasa', '0.17')], 'TAX_RATE_NOT_IN_CATALOG'],
    'ISR retenido 36 %' => [[phase634Rule('001', 'retencion', 'tasa', '0.36')], 'TAX_RATE_NOT_IN_CATALOG'],
    'IEPS 10 % (no es fijo)' => [[phase634Rule('003', 'traslado', 'tasa', '0.10')], 'TAX_RATE_NOT_IN_CATALOG'],
    'IEPS cuota sobre el máximo' => [[phase634Rule('003', 'traslado', 'cuota', '72.1606')], 'TAX_RATE_NOT_IN_CATALOG'],
    'IEPS 3 % retenido' => [[phase634Rule('003', 'retencion', 'tasa', '0.03')], 'TAX_RATE_NOT_IN_CATALOG'],
    'IVA por cuota' => [[phase634Rule('002', 'traslado', 'cuota', '1')], 'TAX_RATE_NOT_IN_CATALOG'],
    'retención exenta' => [[phase634Rule('002', 'retencion', 'exento')], 'TAX_EXEMPT_WITHHOLDING'],
    'tasa sin valor' => [[phase634Rule('002', 'traslado', 'tasa')], 'TAX_RATE_REQUIRED'],
    'IVA 8 % fronterizo' => [[phase634Rule('002', 'traslado', 'tasa', '0.08')], 'TAX_RATE_OUT_OF_SCOPE'],
    'IVA dos veces' => [[phase634Rule('002', 'traslado', 'tasa', '0.16'), phase634Rule('002', 'traslado', 'exento')], 'TAX_DUPLICATED'],
    'IEPS incluido en el precio' => [[phase634Rule('003', 'traslado', 'tasa', '0.08', 'unit')], 'TAX_IEPS_MODE_UNSUPPORTED'],
]);

test('un exento con tasa se rechaza', function () {
    $spec = new TaxSpec('002', TaxType::Traslado, TaxFactorType::Exento, '0.160000');

    expect(array_column((new TaxRuleValidator)->issues([$spec]), 'code'))->toBe(['TAX_EXEMPT_WITH_RATE']);
});

test('D3: lista cerrada de tasas de IVA e ISR retenidos, dentro del rango del SAT', function () {
    expect(phase634Codes([phase634Rule('002', 'retencion', 'tasa', '0.10')]))->toBe(['TAX_WITHHOLDING_RATE_NOT_SUPPORTED'])
        ->and(phase634Codes([phase634Rule('001', 'retencion', 'tasa', '0.20')]))->toBe(['TAX_WITHHOLDING_RATE_NOT_SUPPORTED']);

    foreach (LegalTaxRules::IVA_WITHHOLDING_RATES as $rate) {
        expect(phase634Codes([phase634Rule('002', 'retencion', 'tasa', $rate)]))->toBe([], $rate);
    }
    foreach (LegalTaxRules::ISR_WITHHOLDING_RATES as $rate) {
        expect(phase634Codes([phase634Rule('001', 'retencion', 'tasa', $rate)]))->toBe([], $rate);
    }
});

test('objeto de impuesto', function (array $specs, string $object, string $code) {
    expect(phase634Codes($specs, $object))->toBe([$code]);
})->with([
    '02 sin impuestos' => [[], '02', 'TAX_OBJECT_REQUIRES_TAX'],
    '01 con IVA' => [[phase634Rule('002', 'traslado', 'tasa', '0.16')], '01', 'TAX_OBJECT_WITHOUT_TAX'],
    '04 con IVA' => [[phase634Rule('002', 'traslado', 'tasa', '0.16')], '04', 'TAX_OBJECT_WITHOUT_TAX'],
    '07 sin IEPS' => [[], '07', 'TAX_OBJECT_07_REQUIRES_IEPS'],
    '07 con IVA' => [[phase634Rule('003', 'traslado', 'tasa', '0.08'), phase634Rule('002', 'traslado', 'tasa', '0.16')], '07', 'TAX_OBJECT_07_REQUIRES_IEPS'],
]);

test('reglas legales: sólo avisos, marcados pendiente de contador', function () {
    $honorarios = [phase634Rule('002', 'traslado', 'tasa', '0.16'), phase634Rule('002', 'retencion', 'tasa', '0.106667'), phase634Rule('001', 'retencion', 'tasa', '0.10')];
    $codes = fn (array $warnings) => array_column($warnings, 'code');

    // Persona física (13) a persona moral (12): sin avisos.
    expect(LegalTaxRules::warnings('GODE561231GR8', '612', 'ABC010101AB1', $honorarios))->toBe([])
        // Retención con receptor persona física o genérico.
        ->and($codes(LegalTaxRules::warnings('GODE561231GR8', '612', 'MELM8305281H0', $honorarios)))->toBe(['LEGAL_WITHHOLDING_RECEIVER_NOT_MORAL'])
        ->and($codes(LegalTaxRules::warnings('GODE561231GR8', '612', 'XAXX010101000', $honorarios)))->toBe(['LEGAL_WITHHOLDING_RECEIVER_NOT_MORAL'])
        // ISR retenido con emisor persona moral.
        ->and($codes(LegalTaxRules::warnings('ABC010101AB1', '601', 'XYZ010101AB1', $honorarios)))->toBe(['LEGAL_ISR_WITHHOLDING_ISSUER_MORAL'])
        // RESICO persona física a persona moral sin ISR 1.25 %, o con otra tasa.
        ->and($codes(LegalTaxRules::warnings('GODE561231GR8', '626', 'ABC010101AB1', [phase634Rule('002', 'traslado', 'tasa', '0.16')])))->toBe(['LEGAL_RESICO_ISR_MISSING'])
        ->and($codes(LegalTaxRules::warnings('GODE561231GR8', '626', 'ABC010101AB1', $honorarios)))->toBe(['LEGAL_RESICO_ISR_RATE']);

    foreach (LegalTaxRules::warnings('GODE561231GR8', '612', 'MELM8305281H0', $honorarios) as $warning) {
        expect($warning['pending_accountant'])->toBeTrue()
            ->and($warning['message'])->toContain('Pendiente de contador');
    }
});
