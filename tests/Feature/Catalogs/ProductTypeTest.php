<?php

use App\Enums\ProductType;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;

test('un tipo de producto inválido devuelve 422', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user, 'api')->postJson('/api/products', [
        'name' => 'Producto con tipo inválido',
        'descripcion' => 'Descripción',
        'precio_unitario' => 10,
        'clave_producto' => 'BADTYPE1',
        'type' => 'digital-download',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('type');
});

test('un producto nuevo sin especificar type recibe el default product', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user, 'api')->postJson('/api/products', [
        'name' => 'Producto sin type',
        'descripcion' => 'Descripción',
        'precio_unitario' => 10,
        'clave_producto' => 'NOTYPE01',
    ]);

    $response->assertCreated();

    $product = Product::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->findOrFail($response->json('id'));
    expect($product->type)->toBe(ProductType::Product);
});

test('un producto puede crearse explícitamente como service', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user, 'api')->postJson('/api/products', [
        'name' => 'Servicio de consultoría',
        'descripcion' => 'Descripción',
        'precio_unitario' => 500,
        'clave_producto' => 'SERVTYPE',
        'type' => 'service',
    ]);

    $response->assertCreated()->assertJsonPath('type', 'service');
});

test('productos existentes (creados antes de la columna type) reciben el tipo por defecto esperado', function () {
    $company = Company::factory()->create();

    // Simula un producto creado antes de que existiera la columna `type`,
    // insertando directamente sin especificarla — MySQL/SQLite aplican el
    // default de columna ('product') automáticamente.
    $productId = \Illuminate\Support\Facades\DB::table('products')->insertGetId([
        'company_id' => $company->id,
        'name' => 'Producto preexistente',
        'descripcion' => 'Antes de products.type',
        'precio_unitario' => 20,
        'clave_producto' => 'OLDPROD1',
        'iva' => 16,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $product = Product::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->findOrFail($productId);

    expect($product->type)->toBe(ProductType::Product);
});
