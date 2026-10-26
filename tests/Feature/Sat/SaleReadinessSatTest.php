<?php

use App\Models\Client;
use App\Models\Company;
use App\Models\Product;
use App\Models\Sale;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Billing\SaleBillingReadinessService;
use Database\Seeders\TaxRateSeeder;
use Illuminate\Support\Facades\DB;

/*
| Fase 6.26: el readiness de la venta detecta valores inválidos según el SAT
| (no sólo faltantes), la forma de pago fuera de c_FormaPago y la
| incoherencia entre el objeto de impuesto y el IVA real de cada línea.
*/

beforeEach(fn () => (new TaxRateSeeder)->run());

/** @return array{User, Sale, Client, Product} */
function satReadinessSale(array $product = [], ?string $taxRate = null, array $sale = ['payment_form' => '03', 'payment_method' => 'PUE'], array $company = []): array
{
    $company = Company::factory()->create($company);
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $product = Product::factory()->create(['company_id' => $company->id, 'clave_unidad' => 'H87', ...$product]);

    $created = test()->actingAs($user, 'api')->postJson('/api/sales', ['client_id' => $client->id, ...$sale])->assertCreated()->json();
    test()->actingAs($user, 'api')->postJson("/api/sales/{$created['id']}/items", [
        'product_id' => $product->id,
        'quantity' => 1,
        ...($taxRate ? ['tax_rate_id' => TaxRate::where('name', $taxRate)->value('id')] : []),
    ])->assertCreated();

    return [$user, Sale::withoutGlobalScopes()->findOrFail($created['id']), $client, $product];
}

/** @return array{errors: array<int, array{code: string, field: string}>, warnings: array<int, array{code: string, field: string}>} */
function satReadiness(User $user, Sale $sale): array
{
    return test()->actingAs($user, 'api')->getJson("/api/sales/{$sale->id}/billing-readiness")->assertOk()->json();
}

function satCodes(array $entries): array
{
    return collect($entries)->pluck('code')->all();
}

test('una venta con datos SAT válidos y objeto coherente con la línea está lista', function (array $product, ?string $taxRate) {
    [$user, $sale] = satReadinessSale($product, $taxRate);

    expect(satReadiness($user, $sale)['errors'])->toBe([]);
})->with([
    'objeto 01 sin IVA' => [['objeto_imp' => '01'], null],
    'objeto 02 con IVA 16 %' => [['objeto_imp' => '02'], 'IVA 16%'],
    'objeto 02 con IVA 0 %' => [['objeto_imp' => '02'], 'IVA 0%'],
    'objeto 02 exento' => [['objeto_imp' => '02'], 'IVA Exento'],
]);

test('detecta un cliente con RFC, régimen o uso inválidos aunque no falten', function () {
    [$user, $sale, $client] = satReadinessSale();
    // Datos capturados antes de la Fase 6.26 (escritura directa, sin Form Request).
    DB::table('clients')->where('id', $client->id)->update(['rfc' => 'ABC123', 'codigo_postal' => '123', 'regimen_fiscal' => '999', 'uso_cfdi' => 'G03']);

    $errors = collect(satReadiness($user, $sale)['errors']);

    expect($errors->pluck('code')->all())->toContain('CLIENT_RFC_INVALID', 'CLIENT_POSTAL_CODE_INVALID', 'CLIENT_FISCAL_REGIME_INVALID')
        ->and($errors->firstWhere('code', 'CLIENT_RFC_INVALID')['field'])->toBe('client.rfc');
});

test('detecta un uso de CFDI incompatible con el régimen del cliente', function () {
    [$user, $sale, $client] = satReadinessSale();
    DB::table('clients')->where('id', $client->id)->update(['rfc' => 'GODE561231GR8', 'regimen_fiscal' => '612', 'uso_cfdi' => 'CN01']);

    expect(satCodes(satReadiness($user, $sale)['errors']))->toContain('CLIENT_REGIME_USE_INCOMPATIBLE');
});

test('detecta claves de producto y objeto de impuesto inválidos', function (array $product, string $code) {
    [$user, $sale] = satReadinessSale($product);

    expect(satCodes(satReadiness($user, $sale)['errors']))->toContain($code);
})->with([
    'clave con letras' => [['clave_producto' => 'dasdsada'], 'ITEM_PRODUCT_SAT_KEY_INVALID'],
    'clave inexistente' => [['clave_producto' => '99999999'], 'ITEM_PRODUCT_SAT_KEY_INVALID'],
    'objeto fuera de catálogo' => [['objeto_imp' => '09'], 'ITEM_PRODUCT_OBJETO_IMP_INVALID'],
]);

test('una unidad inexistente es warning en el readiness interno y error antes de facturar', function () {
    [$user, $sale] = satReadinessSale(['clave_unidad' => '888']);

    expect(satCodes(satReadiness($user, $sale)['errors']))->toContain('ITEM_PRODUCT_UNIT_KEY_INVALID');

    $internal = app(SaleBillingReadinessService::class)->evaluate($sale->fresh());
    expect(satCodes($internal['warnings']))->toContain('ITEM_PRODUCT_UNIT_KEY_INVALID')
        ->and(satCodes($internal['errors']))->not->toContain('ITEM_PRODUCT_UNIT_KEY_INVALID');
});

test('el objeto de impuesto debe coincidir con el IVA real de la línea', function (array $product, ?string $taxRate) {
    [$user, $sale] = satReadinessSale($product, $taxRate);

    $errors = collect(satReadiness($user, $sale)['errors']);

    expect($errors->pluck('code')->all())->toContain('ITEM_TAX_OBJECT_MISMATCH')
        ->and($errors->firstWhere('code', 'ITEM_TAX_OBJECT_MISMATCH')['field'])->toEndWith('.tax_rate_id');
})->with([
    'objeto 02 sin IVA en la línea' => [['objeto_imp' => '02'], null],
    'objeto 01 con IVA en la línea' => [['objeto_imp' => '01'], 'IVA 16%'],
    'objeto 03 con IVA en la línea' => [['objeto_imp' => '03'], 'IVA 0%'],
]);

test('las ventas aceptan el catálogo c_FormaPago completo y rechazan lo que no está en él', function () {
    [$user, , $client] = satReadinessSale();

    $this->actingAs($user, 'api')->postJson('/api/sales', ['client_id' => $client->id, 'payment_form' => '31', 'payment_method' => 'PUE'])
        ->assertCreated();

    $this->actingAs($user, 'api')->postJson('/api/sales', ['client_id' => $client->id, 'payment_form' => '07', 'payment_method' => 'PUE'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['payment_form' => 'no existe en el catálogo c_FormaPago']);
});

test('una forma de pago predeterminada fuera de catálogo bloquea con SALE_PAYMENT_FORM_INVALID', function () {
    [$user, $sale] = satReadinessSale(sale: [], company: ['default_payment_form' => '07', 'default_payment_method' => 'PUE']);

    expect(satCodes(satReadiness($user, $sale)['errors']))->toContain('SALE_PAYMENT_FORM_INVALID');
});

test('facturar una venta con claves inválidas responde 422 con los problemas y no crea nada', function () {
    [$user, $sale] = satReadinessSale(['clave_producto' => '99999999']);

    $response = $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/invoice")->assertUnprocessable();

    expect(satCodes($response->json('errors')))->toContain('ITEM_PRODUCT_SAT_KEY_INVALID')
        ->and(DB::table('invoices')->where('sale_id', $sale->id)->exists())->toBeFalse();
});
