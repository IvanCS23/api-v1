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
        'clave_producto' => '43232408',
        'clave_unidad' => 'H87',
        'objeto_imp' => '01',
        'company_id' => $companyB->id, // intento de inyectar otra empresa vía payload
    ]);

    $response->assertCreated();

    $created = Product::withoutGlobalScope(CompanyScope::class)->findOrFail($response->json('id'));
    expect($created->company_id)->toBe($companyA->id);
});

/**
 * Fase 6.26: clave_producto es la clasificación SAT (c_ClaveProdServ), no
 * un identificador, así que ya no es única ni por empresa: dos productos de
 * la misma empresa pueden compartirla. El identificador propio sigue siendo
 * no_identificacion (único por empresa).
 */
test('dos productos pueden compartir la clave SAT, en la misma empresa o en otra', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    Product::factory()->create(['company_id' => $companyA->id, 'clave_producto' => '43232408']);

    $userB = User::factory()->create(['company_id' => $companyB->id]);

    $response = $this->actingAs($userB, 'api')->postJson('/api/products', [
        'name' => 'Producto de otra empresa',
        'descripcion' => 'Descripción',
        'precio_unitario' => 50,
        'clave_producto' => '43232408',
        'clave_unidad' => 'H87',
        'objeto_imp' => '01',
    ]);
    $response->assertCreated();

    $userA = User::factory()->create(['company_id' => $companyA->id]);

    $sameKey = $this->actingAs($userA, 'api')->postJson('/api/products', [
        'name' => 'Otro producto con la misma clave SAT',
        'descripcion' => 'Descripción',
        'precio_unitario' => 50,
        'clave_producto' => '43232408',
        'clave_unidad' => 'H87',
        'objeto_imp' => '01',
    ]);
    $sameKey->assertCreated();

    expect(Product::withoutGlobalScope(CompanyScope::class)
        ->where('company_id', $companyA->id)
        ->where('clave_producto', '43232408')
        ->count())->toBe(2);
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
