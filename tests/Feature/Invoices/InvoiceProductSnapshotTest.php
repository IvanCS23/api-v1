<?php

use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use App\Support\Tenant\CurrentTenant;

/**
 * Mismo patrón que billableSale()/billableSaleForIsolation() en los
 * demás archivos de tests/Feature/Invoices — redeclarada bajo otro
 * nombre para no acoplar este archivo a los otros.
 */
function billableSaleForProductSnapshot(User $user, Client $client, Product $product): Sale
{
    $sale = test()->actingAs($user, 'api')->postJson('/api/sales', ['client_id' => $client->id])->json();
    test()->actingAs($user, 'api')->postJson("/api/sales/{$sale['id']}/items", ['product_id' => $product->id, 'quantity' => 1])->assertCreated();
    test()->actingAs($user, 'api')->postJson("/api/sales/{$sale['id']}/submit")->assertOk();
    test()->actingAs($user, 'api')->postJson("/api/sales/{$sale['id']}/confirm")->assertOk();

    app(CurrentTenant::class)->set($client->company_id);

    return Sale::findOrFail($sale['id']);
}

test('product_no_identificacion se copia al InvoiceItem', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $product = Product::factory()->create(['company_id' => $company->id, 'no_identificacion' => 'NOID-001']);
    $sale = billableSaleForProductSnapshot($user, $client, $product);

    $response = $this->actingAs($user, 'api')->postJson('/api/invoices', ['sale_id' => $sale->id]);
    $response->assertCreated();

    app(CurrentTenant::class)->set($company->id);
    $item = Invoice::with('items')->findOrFail($response->json('id'))->items->first();

    expect($item->product_no_identificacion)->toBe('NOID-001');
});

test('product_description se copia desde products.descripcion, y description conserva el texto de SaleItem sin sobrescribirse', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $product = Product::factory()->create([
        'company_id' => $company->id,
        'name' => 'Nombre Comercial Producto',
        'descripcion' => 'Descripción fiscal detallada del producto SAT',
    ]);
    $sale = billableSaleForProductSnapshot($user, $client, $product);

    $response = $this->actingAs($user, 'api')->postJson('/api/invoices', ['sale_id' => $sale->id]);
    $response->assertCreated();

    app(CurrentTenant::class)->set($company->id);
    $item = Invoice::with('items')->findOrFail($response->json('id'))->items->first();

    // description viene del SaleItem (que a su vez copió product.name al
    // agregarse la línea a la venta) — nunca se reemplaza por product_description.
    expect($item->description)->toBe('Nombre Comercial Producto')
        ->and($item->product_description)->toBe('Descripción fiscal detallada del producto SAT')
        ->and($item->description)->not->toBe($item->product_description);
});

test('product_objeto_imp se copia al InvoiceItem', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $product = Product::factory()->create(['company_id' => $company->id, 'objeto_imp' => '02']);
    $sale = billableSaleForProductSnapshot($user, $client, $product);

    $response = $this->actingAs($user, 'api')->postJson('/api/invoices', ['sale_id' => $sale->id]);
    $response->assertCreated();

    app(CurrentTenant::class)->set($company->id);
    $item = Invoice::with('items')->findOrFail($response->json('id'))->items->first();

    expect($item->product_objeto_imp)->toBe('02');
});

test('los snapshots de producto permanecen iguales después de modificar el Product original', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $product = Product::factory()->create([
        'company_id' => $company->id,
        'no_identificacion' => 'ORIGINAL-ID',
        'descripcion' => 'Descripción original',
        'objeto_imp' => '02',
        'clave_producto' => 'ORIG1234',
        'clave_unidad' => 'H87',
    ]);
    $sale = billableSaleForProductSnapshot($user, $client, $product);

    $response = $this->actingAs($user, 'api')->postJson('/api/invoices', ['sale_id' => $sale->id]);
    $response->assertCreated();

    app(CurrentTenant::class)->set($company->id);
    $item = Invoice::with('items')->findOrFail($response->json('id'))->items->first();

    $product->update([
        'no_identificacion' => 'CAMBIADO-ID',
        'descripcion' => 'Descripción cambiada',
        'objeto_imp' => '01',
        'clave_producto' => 'ZZZZ9999',
        'clave_unidad' => 'XYZ',
    ]);

    $item->refresh();
    expect($item->product_no_identificacion)->toBe('ORIGINAL-ID')
        ->and($item->product_description)->toBe('Descripción original')
        ->and($item->product_objeto_imp)->toBe('02')
        ->and($item->product_clave_producto)->toBe('ORIG1234')
        ->and($item->product_clave_unidad)->toBe('H87');
});

test('una venta con clave_producto faltante no puede convertirse', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $sale = billableSaleForProductSnapshot($user, $client, $product);
    app(CurrentTenant::class)->set($company->id);
    $product->update(['clave_producto' => '']);

    $response = $this->actingAs($user, 'api')->postJson('/api/invoices', ['sale_id' => $sale->id]);

    $response->assertStatus(422);
    expect(collect($response->json('errors'))->pluck('code'))->toContain('ITEM_PRODUCT_SAT_KEY_MISSING');

    app(CurrentTenant::class)->set($company->id);
    expect(Invoice::where('sale_id', $sale->id)->exists())->toBeFalse();
});

test('una venta con objeto_imp faltante no puede convertirse', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $sale = billableSaleForProductSnapshot($user, $client, $product);
    app(CurrentTenant::class)->set($company->id);
    $product->update(['objeto_imp' => '']);

    $response = $this->actingAs($user, 'api')->postJson('/api/invoices', ['sale_id' => $sale->id]);

    $response->assertStatus(422);
    expect(collect($response->json('errors'))->pluck('code'))->toContain('ITEM_PRODUCT_OBJETO_IMP_MISSING');

    app(CurrentTenant::class)->set($company->id);
    expect(Invoice::where('sale_id', $sale->id)->exists())->toBeFalse();
});

test('una conversión fallida por objeto_imp faltante no deja Invoice ni InvoiceItem parcial', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $product = Product::factory()->create(['company_id' => $company->id, 'objeto_imp' => '']);
    $sale = billableSaleForProductSnapshot($user, $client, $product);

    $this->actingAs($user, 'api')->postJson('/api/invoices', ['sale_id' => $sale->id])->assertStatus(422);

    app(CurrentTenant::class)->set($company->id);
    expect(Invoice::where('sale_id', $sale->id)->exists())->toBeFalse()
        ->and(InvoiceItem::withoutGlobalScope(CompanyScope::class)->where('company_id', $company->id)->exists())->toBeFalse();
});
