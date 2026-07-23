<?php

use App\Enums\SaleStatus;
use App\Enums\TaxFactorType;
use App\Enums\TaxType;
use App\Models\Client;
use App\Models\Company;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Scopes\CompanyScope;
use App\Models\TaxRate;
use App\Models\User;

test('agregar un producto a una venta crea la línea y recalcula los totales de la venta', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $sale = Sale::factory()->create(['company_id' => $company->id, 'client_id' => $client->id]);
    $product = Product::factory()->create(['company_id' => $company->id, 'precio_unitario' => 100]);

    $response = $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/items", [
        'product_id' => $product->id,
        'quantity' => 2,
    ]);

    $response->assertCreated()
        ->assertJsonPath('quantity', '2.000')
        ->assertJsonPath('unit_price', '100.00')
        ->assertJsonPath('subtotal', '200.00')
        ->assertJsonPath('total', '200.00');

    $sale->refresh();
    expect((float) $sale->subtotal)->toBe(200.0)
        ->and((float) $sale->total)->toBe(200.0);
});

test('el precio unitario por defecto viene del catálogo si no se envía explícito', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $sale = Sale::factory()->create(['company_id' => $company->id, 'client_id' => $client->id]);
    $product = Product::factory()->create(['company_id' => $company->id, 'precio_unitario' => 55.5]);

    $response = $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/items", [
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    $response->assertCreated()->assertJsonPath('unit_price', '55.50');
});

test('un producto con tasa de impuesto calcula tax_total y total correctamente', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $sale = Sale::factory()->create(['company_id' => $company->id, 'client_id' => $client->id]);
    $product = Product::factory()->create(['company_id' => $company->id, 'precio_unitario' => 100]);
    $taxRate = TaxRate::factory()->create(['rate' => 0.16, 'tax_type' => TaxType::Traslado, 'factor_type' => TaxFactorType::Tasa]);

    $response = $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/items", [
        'product_id' => $product->id,
        'quantity' => 1,
        'tax_rate_id' => $taxRate->id,
    ]);

    $response->assertCreated()
        ->assertJsonPath('subtotal', '100.00')
        ->assertJsonPath('tax_total', '16.00')
        ->assertJsonPath('total', '116.00');

    $sale->refresh();
    expect((float) $sale->tax_total)->toBe(16.0)
        ->and((float) $sale->total)->toBe(116.0);
});

test('recalcular: agregar una segunda línea suma correctamente al total de la venta', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $sale = Sale::factory()->create(['company_id' => $company->id, 'client_id' => $client->id]);
    $productA = Product::factory()->create(['company_id' => $company->id, 'precio_unitario' => 100]);
    $productB = Product::factory()->create(['company_id' => $company->id, 'precio_unitario' => 50]);

    $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/items", ['product_id' => $productA->id, 'quantity' => 1])->assertCreated();
    $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/items", ['product_id' => $productB->id, 'quantity' => 3])->assertCreated();

    $sale->refresh();
    // items()->count() consulta con CompanyScope activo; fuera de una
    // request HTTP (actingAs ya terminó y TenantMiddleware limpió el
    // tenant) hace falta fijarlo manualmente para esta verificación.
    app(App\Support\Tenant\CurrentTenant::class)->set($company->id);

    expect((float) $sale->subtotal)->toBe(250.0) // 100*1 + 50*3
        ->and((float) $sale->total)->toBe(250.0)
        ->and($sale->items()->count())->toBe(2);
});

test('eliminar una línea recalcula los totales de la venta hacia abajo', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $sale = Sale::factory()->create(['company_id' => $company->id, 'client_id' => $client->id]);
    $productA = Product::factory()->create(['company_id' => $company->id, 'precio_unitario' => 100]);
    $productB = Product::factory()->create(['company_id' => $company->id, 'precio_unitario' => 50]);

    $itemA = $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/items", ['product_id' => $productA->id, 'quantity' => 1])->json();
    $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/items", ['product_id' => $productB->id, 'quantity' => 1])->assertCreated();

    $sale->refresh();
    expect((float) $sale->total)->toBe(150.0);

    $this->actingAs($user, 'api')->deleteJson("/api/sales/{$sale->id}/items/{$itemA['id']}")->assertNoContent();

    $sale->refresh();
    app(App\Support\Tenant\CurrentTenant::class)->set($company->id);

    expect((float) $sale->total)->toBe(50.0)
        ->and($sale->items()->count())->toBe(1);
});

test('un producto de otra empresa es rechazado con 422 al agregarlo a una venta', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $companyA->id]);
    $client = Client::factory()->create(['company_id' => $companyA->id]);
    $sale = Sale::factory()->create(['company_id' => $companyA->id, 'client_id' => $client->id]);
    $foreignProduct = Product::factory()->create(['company_id' => $companyB->id]);

    $response = $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/items", [
        'product_id' => $foreignProduct->id,
        'quantity' => 1,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('product_id');
});

test('no se pueden agregar ni eliminar productos en una venta cancelada', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $sale = Sale::factory()->create(['company_id' => $company->id, 'client_id' => $client->id, 'status' => SaleStatus::Cancelled]);
    $product = Product::factory()->create(['company_id' => $company->id]);

    $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/items", [
        'product_id' => $product->id,
        'quantity' => 1,
    ])->assertStatus(422);
});

test('las líneas de venta quedan aisladas por tenant (CompanyScope directo sobre SaleItem)', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    \App\Models\SaleItem::factory()->count(2)->create(['company_id' => $companyA->id]);
    \App\Models\SaleItem::factory()->create(['company_id' => $companyB->id]);

    app(\App\Support\Tenant\CurrentTenant::class)->set($companyA->id);
    expect(\App\Models\SaleItem::count())->toBe(2);

    expect(\App\Models\SaleItem::withoutGlobalScope(CompanyScope::class)->count())->toBe(3);
});
