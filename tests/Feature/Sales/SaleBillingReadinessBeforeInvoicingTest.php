<?php

use App\Enums\SaleStatus;
use App\Enums\TaxFactorType;
use App\Enums\TaxType;
use App\Models\Client;
use App\Models\Company;
use App\Models\Product;
use App\Models\Sale;
use App\Models\TaxRate;
use App\Models\User;

/**
 * Fase 6.25: GET /sales/{id}/billing-readiness responde en los términos de
 * la orquestación: borrador y pendiente pueden facturarse, pero el pago
 * efectivo (venta o empresa) debe estar completo y ser compatible, y no se
 * admiten retenciones.
 */
/** @return array{Company, User, Sale} */
function phase625DraftSale(array $company = [], array $sale = [], ?TaxRate $taxRate = null): array
{
    $company = Company::factory()->create($company);
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $product = Product::factory()->create(['company_id' => $company->id, 'clave_unidad' => 'H87']);

    $created = test()->actingAs($user, 'api')->postJson('/api/sales', ['client_id' => $client->id, ...$sale])->assertCreated()->json();
    test()->actingAs($user, 'api')->postJson("/api/sales/{$created['id']}/items", [
        'product_id' => $product->id,
        'quantity' => 1,
        ...($taxRate ? ['tax_rate_id' => $taxRate->id] : []),
    ])->assertCreated();

    return [$company, $user, Sale::withoutGlobalScopes()->findOrFail($created['id'])];
}

function phase625Codes(User $user, Sale $sale): array
{
    return collect(test()->actingAs($user, 'api')->getJson("/api/sales/{$sale->id}/billing-readiness")->assertOk()->json('errors'))
        ->pluck('code')
        ->all();
}

test('una venta en borrador con pago completo está lista para facturar', function () {
    [, $user, $sale] = phase625DraftSale(sale: ['payment_form' => '03', 'payment_method' => 'PUE']);

    expect($sale->status)->toBe(SaleStatus::Draft);
    $this->actingAs($user, 'api')->getJson("/api/sales/{$sale->id}/billing-readiness")
        ->assertOk()
        ->assertJsonPath('ready', true)
        ->assertJsonPath('errors', []);
});

test('sin pago en la venta ni en la empresa: faltan forma y método', function () {
    [, $user, $sale] = phase625DraftSale();

    expect(phase625Codes($user, $sale))->toContain('SALE_PAYMENT_FORM_MISSING', 'SALE_PAYMENT_METHOD_MISSING');
});

test('el pago predeterminado de la empresa cubre a la venta', function () {
    [, $user, $sale] = phase625DraftSale(company: ['default_payment_form' => '03', 'default_payment_method' => 'PUE']);

    expect(phase625Codes($user, $sale))->not->toContain('SALE_PAYMENT_FORM_MISSING', 'SALE_PAYMENT_METHOD_MISSING');
});

test('la combinación efectiva incompatible se reporta con su código', function (array $company, array $sale, string $code) {
    [, $user, $created] = phase625DraftSale(company: $company, sale: $sale);

    expect(phase625Codes($user, $created))->toContain($code);
})->with([
    // La venta sólo trae el método; la forma sale de la empresa.
    'PUE con forma 99 de la empresa' => [['default_payment_form' => '99'], ['payment_method' => 'PUE'], 'SALE_PUE_FORBIDS_FORM_99'],
    // Fase 6.28: la venta sin método hereda el PPD de la empresa.
    'PPD heredado de la empresa' => [['default_payment_form' => '99', 'default_payment_method' => 'PPD'], [], 'SALE_PPD_NOT_AVAILABLE'],
]);

test('Fase 6.28: una venta PPD guardada antes del bloqueo reporta sólo SALE_PPD_NOT_AVAILABLE', function () {
    [, $user, $sale] = phase625DraftSale(sale: ['payment_form' => '03', 'payment_method' => 'PUE']);
    $sale->forceFill(['payment_form' => '03', 'payment_method' => 'PPD'])->save();

    $errors = collect($this->actingAs($user, 'api')->getJson("/api/sales/{$sale->id}/billing-readiness")->assertOk()->json('errors'));

    expect($errors->pluck('code')->all())->toContain('SALE_PPD_NOT_AVAILABLE')
        ->not->toContain('SALE_PPD_REQUIRES_FORM_99')
        ->and($errors->firstWhere('code', 'SALE_PPD_NOT_AVAILABLE')['message'])->toContain('complemento de pago');
});

test('una línea con retención no se puede facturar todavía', function () {
    $retention = TaxRate::create([
        'code' => '001',
        'name' => 'ISR Retenido 10%',
        'rate' => 0.10,
        'tax_type' => TaxType::Retencion,
        'factor_type' => TaxFactorType::Tasa,
        'active' => true,
    ]);
    [, $user, $sale] = phase625DraftSale(sale: ['payment_form' => '03', 'payment_method' => 'PUE'], taxRate: $retention);

    expect(phase625Codes($user, $sale))->toContain('ITEM_TAX_WITHHOLDING_UNSUPPORTED');
});

test('una venta cancelada reporta SALE_CANCELLED', function () {
    [, $user, $sale] = phase625DraftSale(sale: ['payment_form' => '03', 'payment_method' => 'PUE']);
    $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/cancel")->assertOk();

    expect(phase625Codes($user, $sale))->toContain('SALE_CANCELLED');
});
