<?php

use App\Models\Company;
use App\Models\Product;
use App\Models\Scopes\CompanyScope;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Auditoría: StoreProductRequest::validated() solo elimina NULL
|--------------------------------------------------------------------------
|
| El override de validated() usa array_filter($validated, fn ($v) => $v
| !== null) — nunca array_filter($values) sin callback. Estos tests lo
| demuestran con campos reales del producto (precio_unitario, iva,
| iva_retenido), no con campos inventados.
*/

test('precio_unitario en 0 se conserva (no se filtra como si fuera vacío)', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user, 'api')->postJson('/api/products', [
        'name' => 'Producto gratuito',
        'descripcion' => 'Precio en cero',
        'precio_unitario' => 0,
        'clave_producto' => 'ZEROPRC1',
    ]);

    // Si el filtro hubiera eliminado 0 (comparación truthy), la columna
    // precio_unitario (NOT NULL, sin default) habría quedado fuera del
    // create() y esto fallaría con un QueryException antes de responder.
    $response->assertCreated()->assertJsonPath('precio_unitario', '0.00');

    $product = Product::withoutGlobalScope(CompanyScope::class)->findOrFail($response->json('id'));
    expect((float) $product->precio_unitario)->toBe(0.0);
});

test('iva en 0 (int) se conserva', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user, 'api')->postJson('/api/products', [
        'name' => 'Producto IVA 0',
        'descripcion' => 'Descripción',
        'precio_unitario' => 100,
        'clave_producto' => 'IVAZERO1',
        'iva' => 0,
    ]);

    $response->assertCreated();

    $product = Product::withoutGlobalScope(CompanyScope::class)->findOrFail($response->json('id'));
    expect((float) $product->iva)->toBe(0.0);
});

test('iva_retenido como "0" (string) se conserva', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user, 'api')->postJson('/api/products', [
        'name' => 'Producto retención string 0',
        'descripcion' => 'Descripción',
        'precio_unitario' => 100,
        'clave_producto' => 'STRZERO1',
        'iva_retenido' => '0',
    ]);

    $response->assertCreated();

    $product = Product::withoutGlobalScope(CompanyScope::class)->findOrFail($response->json('id'));
    expect((float) $product->iva_retenido)->toBe(0.0);
});

test('iva_retenido omitido (no enviado) queda NULL, no 0 ni ausente por error', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user, 'api')->postJson('/api/products', [
        'name' => 'Producto sin retención',
        'descripcion' => 'Descripción',
        'precio_unitario' => 100,
        'clave_producto' => 'NORETEN1',
    ]);

    $response->assertCreated();

    $product = Product::withoutGlobalScope(CompanyScope::class)->findOrFail($response->json('id'));
    expect($product->iva_retenido)->toBeNull();
});

test('iva_retenido enviado como cadena vacía se normaliza a NULL y se filtra del create (comportamiento preexistente)', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user, 'api')->postJson('/api/products', [
        'name' => 'Producto retención vacía',
        'descripcion' => 'Descripción',
        'precio_unitario' => 100,
        'clave_producto' => 'EMPTYRE1',
        'iva_retenido' => '',
    ]);

    $response->assertCreated();

    $product = Product::withoutGlobalScope(CompanyScope::class)->findOrFail($response->json('id'));
    expect($product->iva_retenido)->toBeNull();
});
