<?php

use App\Enums\TaxFactorType;
use App\Enums\TaxType;
use App\Models\Client;
use App\Models\Company;
use App\Models\Product;
use App\Models\Sale;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\Tenant\CurrentTenant;

/**
 * Crea una Sale Confirmed con una línea coherente, pasando siempre por
 * el flujo real (store -> item -> submit -> confirm) para que subtotal/
 * tax_total/total queden recalculados de forma genuina, no fabricados.
 */
function confirmedSaleWithItem(User $user, Client $client, Product $product): Sale
{
    $sale = test()->actingAs($user, 'api')->postJson('/api/sales', ['client_id' => $client->id])->json();
    test()->actingAs($user, 'api')->postJson("/api/sales/{$sale['id']}/items", ['product_id' => $product->id, 'quantity' => 1])->assertCreated();
    test()->actingAs($user, 'api')->postJson("/api/sales/{$sale['id']}/submit")->assertOk();
    test()->actingAs($user, 'api')->postJson("/api/sales/{$sale['id']}/confirm")->assertOk();

    app(CurrentTenant::class)->set($client->company_id);

    return Sale::findOrFail($sale['id']);
}

test('una venta fiscalmente completa da ready=true sin errores', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $product = Product::factory()->create(['company_id' => $company->id, 'clave_unidad' => 'H87']);
    $sale = confirmedSaleWithItem($user, $client, $product);

    $response = $this->actingAs($user, 'api')->getJson("/api/sales/{$sale->id}/billing-readiness");

    $response->assertOk()
        ->assertJsonPath('ready', true)
        ->assertJsonPath('errors', [])
        ->assertJsonPath('warnings', []);
});

test('una venta no confirmada no está lista para facturarse', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $sale = Sale::factory()->create(['company_id' => $company->id, 'client_id' => $client->id]);

    $response = $this->actingAs($user, 'api')->getJson("/api/sales/{$sale->id}/billing-readiness");

    $response->assertOk()->assertJsonPath('ready', false);
    expect(collect($response->json('errors'))->pluck('code'))->toContain('SALE_NOT_CONFIRMED');
});

test('un cliente sin RFC bloquea la facturación', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id, 'rfc' => '']);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $sale = confirmedSaleWithItem($user, $client, $product);

    $response = $this->actingAs($user, 'api')->getJson("/api/sales/{$sale->id}/billing-readiness");

    $response->assertOk()->assertJsonPath('ready', false);
    expect(collect($response->json('errors'))->pluck('code'))->toContain('CLIENT_RFC_MISSING');
});

test('un cliente sin código postal bloquea la facturación', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id, 'codigo_postal' => '']);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $sale = confirmedSaleWithItem($user, $client, $product);

    $response = $this->actingAs($user, 'api')->getJson("/api/sales/{$sale->id}/billing-readiness");

    $response->assertOk()->assertJsonPath('ready', false);
    expect(collect($response->json('errors'))->pluck('code'))->toContain('CLIENT_POSTAL_CODE_MISSING');
});

test('un cliente sin régimen fiscal bloquea la facturación', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id, 'regimen_fiscal' => '']);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $sale = confirmedSaleWithItem($user, $client, $product);

    $response = $this->actingAs($user, 'api')->getJson("/api/sales/{$sale->id}/billing-readiness");

    $response->assertOk()->assertJsonPath('ready', false);
    expect(collect($response->json('errors'))->pluck('code'))->toContain('CLIENT_FISCAL_REGIME_MISSING');
});

test('un producto sin clave fiscal SAT bloquea la facturación', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $sale = confirmedSaleWithItem($user, $client, $product);
    app(CurrentTenant::class)->set($company->id);
    $product->update(['clave_producto' => '']);

    $response = $this->actingAs($user, 'api')->getJson("/api/sales/{$sale->id}/billing-readiness");

    $response->assertOk()->assertJsonPath('ready', false);
    expect(collect($response->json('errors'))->pluck('code'))->toContain('ITEM_PRODUCT_SAT_KEY_MISSING');
});

test('una venta sin líneas no está lista para facturarse', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $sale = Sale::factory()->create(['company_id' => $company->id, 'client_id' => $client->id]);

    $response = $this->actingAs($user, 'api')->getJson("/api/sales/{$sale->id}/billing-readiness");

    $response->assertOk()->assertJsonPath('ready', false);
    expect(collect($response->json('errors'))->pluck('code'))->toContain('SALE_NO_ITEMS');
});

test('totales alterados manualmente bloquean la facturación', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $sale = confirmedSaleWithItem($user, $client, $product);
    app(CurrentTenant::class)->set($company->id);
    $sale->forceFill(['total' => $sale->total + 500])->save();

    $response = $this->actingAs($user, 'api')->getJson("/api/sales/{$sale->id}/billing-readiness");

    $response->assertOk()->assertJsonPath('ready', false);
    expect(collect($response->json('errors'))->pluck('code'))->toContain('SALE_TOTALS_MISMATCH');
});

test('datos cross-tenant en las líneas bloquean la facturación', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $companyA->id]);
    $client = Client::factory()->create(['company_id' => $companyA->id]);
    $product = Product::factory()->create(['company_id' => $companyA->id]);
    $sale = confirmedSaleWithItem($user, $client, $product);

    app(CurrentTenant::class)->set($companyA->id);
    // Simula corrupción de datos con un UPDATE crudo: BelongsToCompany
    // revierte cualquier intento de cambiar company_id vía Eloquent
    // ->update(), así que solo un ALTER directo en BD puede producir
    // este escenario — exactamente lo que esta prueba busca cubrir.
    \Illuminate\Support\Facades\DB::table('sale_items')
        ->where('id', $sale->items()->first()->id)
        ->update(['company_id' => $companyB->id]);

    $response = $this->actingAs($user, 'api')->getJson("/api/sales/{$sale->id}/billing-readiness");

    $response->assertOk()->assertJsonPath('ready', false);
    expect(collect($response->json('errors'))->pluck('code'))->toContain('TENANT_MISMATCH');
});

test('una tasa de impuesto inactiva en una línea bloquea la facturación', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $taxRate = TaxRate::factory()->create(['rate' => 0.16, 'tax_type' => TaxType::Traslado, 'factor_type' => TaxFactorType::Tasa, 'active' => true]);

    $saleJson = $this->actingAs($user, 'api')->postJson('/api/sales', ['client_id' => $client->id])->json();
    $this->actingAs($user, 'api')->postJson("/api/sales/{$saleJson['id']}/items", ['product_id' => $product->id, 'quantity' => 1, 'tax_rate_id' => $taxRate->id])->assertCreated();
    $this->actingAs($user, 'api')->postJson("/api/sales/{$saleJson['id']}/submit")->assertOk();
    $this->actingAs($user, 'api')->postJson("/api/sales/{$saleJson['id']}/confirm")->assertOk();
    $taxRate->update(['active' => false]);

    $response = $this->actingAs($user, 'api')->getJson("/api/sales/{$saleJson['id']}/billing-readiness");

    $response->assertOk()->assertJsonPath('ready', false);
    expect(collect($response->json('errors'))->pluck('code'))->toContain('ITEM_TAX_RATE_INVALID');
});

test('un producto sin clave de unidad SAT genera un warning pero no bloquea', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $product = Product::factory()->create(['company_id' => $company->id]); // clave_unidad null por defecto.
    $sale = confirmedSaleWithItem($user, $client, $product);

    $response = $this->actingAs($user, 'api')->getJson("/api/sales/{$sale->id}/billing-readiness");

    $response->assertOk()->assertJsonPath('ready', true);
    expect(collect($response->json('warnings'))->pluck('code'))->toContain('ITEM_PRODUCT_UNIT_KEY_MISSING');
});
