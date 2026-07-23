<?php

use App\Models\Company;
use App\Models\Product;
use App\Models\Scopes\CompanyScope;
use App\Models\User;

test('index solo devuelve productos de la empresa del usuario autenticado', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    Product::factory()->count(2)->create(['company_id' => $companyA->id]);
    Product::factory()->count(3)->create(['company_id' => $companyB->id]);

    $user = User::factory()->create(['company_id' => $companyA->id]);

    $response = $this->actingAs($user, 'api')->getJson('/api/products');

    $response->assertOk();
    expect($response->json())->toHaveCount(2);
});

test('store asigna company_id automáticamente desde el usuario autenticado, no desde el payload', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $companyA->id]);

    $response = $this->actingAs($user, 'api')->postJson('/api/products', [
        'name' => 'Producto de prueba',
        'descripcion' => 'Descripción de prueba',
        'precio_unitario' => 150.5,
        'clave_producto' => 'ABCD1234',
        'company_id' => $companyB->id, // intento de inyectar otra empresa vía payload
    ]);

    $response->assertCreated();

    $created = Product::withoutGlobalScope(CompanyScope::class)->findOrFail($response->json('id'));
    expect($created->company_id)->toBe($companyA->id);
});

test('la validación unique de clave_producto es por empresa, no global', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    Product::factory()->create(['company_id' => $companyA->id, 'clave_producto' => 'ZZZZ9999']);

    $userB = User::factory()->create(['company_id' => $companyB->id]);

    $response = $this->actingAs($userB, 'api')->postJson('/api/products', [
        'name' => 'Producto de otra empresa',
        'descripcion' => 'Descripción',
        'precio_unitario' => 50,
        'clave_producto' => 'ZZZZ9999',
    ]);
    $response->assertCreated();

    $userA = User::factory()->create(['company_id' => $companyA->id]);

    $duplicate = $this->actingAs($userA, 'api')->postJson('/api/products', [
        'name' => 'Producto duplicado',
        'descripcion' => 'Descripción',
        'precio_unitario' => 50,
        'clave_producto' => 'ZZZZ9999',
    ]);
    $duplicate->assertStatus(422);
    $duplicate->assertJsonValidationErrors('clave_producto');
});

test('un usuario no puede ver, editar ni borrar productos de otra empresa', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $product = Product::factory()->create(['company_id' => $companyA->id]);
    $userB = User::factory()->create(['company_id' => $companyB->id]);

    $this->actingAs($userB, 'api')->getJson("/api/products/{$product->id}")->assertNotFound();
    $this->actingAs($userB, 'api')->putJson("/api/products/{$product->id}", ['name' => 'Hackeado'])->assertNotFound();
    $this->actingAs($userB, 'api')->deleteJson("/api/products/{$product->id}")->assertNotFound();

    expect($product->fresh()->name)->not->toBe('Hackeado');
});

test('la policy deniega el acceso entre empresas aunque se use withoutGlobalScope', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $product = Product::factory()->create(['company_id' => $companyA->id]);
    $userB = User::factory()->create(['company_id' => $companyB->id]);
    $userA = User::factory()->create(['company_id' => $companyA->id]);

    $productWithoutScope = Product::withoutGlobalScope(CompanyScope::class)->findOrFail($product->id);

    expect($userB->can('view', $productWithoutScope))->toBeFalse()
        ->and($userB->can('update', $productWithoutScope))->toBeFalse()
        ->and($userB->can('delete', $productWithoutScope))->toBeFalse()
        ->and($userA->can('view', $productWithoutScope))->toBeTrue()
        ->and($userA->can('update', $productWithoutScope))->toBeTrue()
        ->and($userA->can('delete', $productWithoutScope))->toBeTrue();
});

test('company_id no se puede modificar manualmente una vez creado el registro', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $product = Product::factory()->create(['company_id' => $companyA->id]);

    $product->company_id = $companyB->id;
    $product->save();

    expect($product->fresh()->company_id)->toBe($companyA->id);
});
