<?php

use App\Enums\TaxFactorType;
use App\Enums\TaxType;
use App\Models\Company;
use App\Models\PaymentChannel;
use App\Models\TaxRate;
use App\Support\Tenant\CurrentTenant;
use Database\Seeders\PaymentChannelSeeder;
use Database\Seeders\TaxRateSeeder;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| payment_channels y tax_rates: catálogos globales
|--------------------------------------------------------------------------
|
| Objetivo #10 (Etapa D) / auditoría Etapa D-revisión: estos catálogos NO
| deben quedar bajo CompanyScope por accidente. No usan BelongsToCompany,
| así que no hay Global Scope que filtrar — estos tests son la prueba de
| regresión: si alguna vez alguien le agrega el trait sin querer, el
| conteo dejaría de coincidir en el segundo assert.
|
| `payment_channels` (antes `payment_methods`, renombrado a propósito):
| es el catálogo interno de canales de cobro del ERP (efectivo,
| transferencia, tarjetas, cheque). NO es ni el "método de pago" CFDI
| (PUE|PPD) ni la "forma de pago" SAT (c_FormaPago) — esos catálogos
| fiscales todavía no existen y se crearán junto con Facturapi (Fase 3).
*/

test('payment_channels no está sujeto a CompanyScope', function () {
    $companyA = Company::factory()->create();

    PaymentChannel::factory()->count(3)->create();

    app(CurrentTenant::class)->set($companyA->id);
    expect(PaymentChannel::count())->toBe(3);

    app(CurrentTenant::class)->clear();
    expect(PaymentChannel::count())->toBe(3);
});

test('tax_rates no está sujeto a CompanyScope', function () {
    $companyA = Company::factory()->create();

    TaxRate::factory()->count(2)->create();

    app(CurrentTenant::class)->set($companyA->id);
    expect(TaxRate::count())->toBe(2);

    app(CurrentTenant::class)->clear();
    expect(TaxRate::count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Esquema y seeders mínimos
|--------------------------------------------------------------------------
*/

test('payment_channels tiene el esquema esperado', function () {
    expect(Schema::hasTable('payment_channels'))->toBeTrue()
        ->and(Schema::hasColumns('payment_channels', ['id', 'code', 'name', 'requires_bank', 'active', 'created_at', 'updated_at']))->toBeTrue();

    PaymentChannel::factory()->create(['code' => 'UNIQUE_CODE']);

    expect(fn () => PaymentChannel::factory()->create(['code' => 'UNIQUE_CODE']))
        ->toThrow(Illuminate\Database\QueryException::class);
});

test('el seeder de payment_channels crea el catálogo base y es idempotente', function () {
    (new PaymentChannelSeeder())->run();
    $countAfterFirstRun = PaymentChannel::count();

    (new PaymentChannelSeeder())->run();
    $countAfterSecondRun = PaymentChannel::count();

    expect($countAfterFirstRun)->toBe(5)
        ->and($countAfterSecondRun)->toBe(5)
        ->and(PaymentChannel::where('code', 'CASH')->exists())->toBeTrue()
        ->and(PaymentChannel::where('code', 'BANK_TRANSFER')->value('requires_bank'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| tax_rates — Objetivo 5 (auditoría)
|--------------------------------------------------------------------------
*/

test('tax_rates tiene el esquema esperado y castea sus enums', function () {
    expect(Schema::hasTable('tax_rates'))->toBeTrue()
        ->and(Schema::hasColumns('tax_rates', ['id', 'code', 'name', 'rate', 'tax_type', 'factor_type', 'active', 'created_at', 'updated_at']))->toBeTrue();

    $rate = TaxRate::factory()->create([
        'tax_type' => TaxType::Retencion,
        'factor_type' => TaxFactorType::Exento,
        'rate' => 0.1,
    ]);

    expect($rate->fresh()->tax_type)->toBe(TaxType::Retencion)
        ->and($rate->fresh()->factor_type)->toBe(TaxFactorType::Exento);
});

test('rate se castea como decimal (string), nunca como float de PHP', function () {
    $rate = TaxRate::factory()->create(['rate' => 0.16]);

    // El cast 'decimal:6' de Eloquent devuelve un string ("0.160000"),
    // no un float — evita los errores de precisión binaria de float en
    // cálculos fiscales futuros (Fase 2/3).
    expect($rate->fresh()->rate)->toBeString()
        ->and($rate->fresh()->rate)->toBe('0.160000');
});

test('el registro IVA Exento tiene una combinación coherente de rate, tax_type y factor_type', function () {
    (new TaxRateSeeder())->run();

    $exento = TaxRate::where('name', 'IVA Exento')->firstOrFail();

    expect((float) $exento->rate)->toBe(0.0)
        ->and($exento->tax_type)->toBe(TaxType::Traslado)
        ->and($exento->factor_type)->toBe(TaxFactorType::Exento);
});

test('las tasas sembradas por el seeder no son negativas', function () {
    (new TaxRateSeeder())->run();

    $hasNegative = TaxRate::query()->get()->contains(fn (TaxRate $rate) => (float) $rate->rate < 0);

    expect($hasNegative)->toBeFalse();
});

test('la factory de tax_rates no genera tasas negativas por defecto', function () {
    $rate = TaxRate::factory()->create();

    expect((float) $rate->rate)->toBeGreaterThanOrEqual(0.0);
});

test('el seeder de tax_rates crea el catálogo base y es idempotente', function () {
    (new TaxRateSeeder())->run();
    $countAfterFirstRun = TaxRate::count();

    (new TaxRateSeeder())->run();
    $countAfterSecondRun = TaxRate::count();

    expect($countAfterFirstRun)->toBe(4)
        ->and($countAfterSecondRun)->toBe(4)
        ->and(TaxRate::where('name', 'IVA 16%')->value('rate'))->toEqual('0.160000');
});
