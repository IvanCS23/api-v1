<?php

use App\Models\Client;
use App\Models\Company;
use App\Models\Employe;
use App\Models\Product;
use App\Models\Scopes\CompanyScope;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Parte 6 / Objetivo #5: company_id enviado en UPDATE no se modifica
|--------------------------------------------------------------------------
|
| Distinto del test de "no se puede modificar manualmente" (que asigna
| company_id directamente sobre el modelo): aquí se envía company_id en
| el body JSON de una request PUT real, a través de UpdateClientRequest/
| UpdateProductRequest/UpdateEmployeRequest, para confirmar que ni
| siquiera llega a $request->validated() (no está en las reglas).
|
*/

test('company_id en el payload de update no modifica el cliente', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $companyA->id]);
    $client = Client::factory()->create(['company_id' => $companyA->id]);

    $response = $this->actingAs($user, 'api')->putJson("/api/clients/{$client->id}", [
        'name' => 'Nombre actualizado',
        'company_id' => $companyB->id,
    ]);

    $response->assertOk();

    $fresh = Client::withoutGlobalScope(CompanyScope::class)->findOrFail($client->id);
    expect($fresh->company_id)->toBe($companyA->id)
        ->and($fresh->name)->toBe('Nombre actualizado');
});

test('company_id en el payload de update no modifica el producto', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $companyA->id]);
    $product = Product::factory()->create(['company_id' => $companyA->id]);

    $response = $this->actingAs($user, 'api')->putJson("/api/products/{$product->id}", [
        'name' => 'Producto actualizado',
        'company_id' => $companyB->id,
    ]);

    $response->assertOk();

    $fresh = Product::withoutGlobalScope(CompanyScope::class)->findOrFail($product->id);
    expect($fresh->company_id)->toBe($companyA->id)
        ->and($fresh->name)->toBe('Producto actualizado');
});

test('company_id en el payload de update no modifica el empleado', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $companyA->id]);
    $employe = Employe::factory()->create(['company_id' => $companyA->id]);

    $response = $this->actingAs($user, 'api')->putJson("/api/employees/{$employe->id}", [
        'nombre' => 'Empleado actualizado',
        'company_id' => $companyB->id,
    ]);

    $response->assertOk();

    $fresh = Employe::withoutGlobalScope(CompanyScope::class)->findOrFail($employe->id);
    expect($fresh->company_id)->toBe($companyA->id)
        ->and($fresh->nombre)->toBe('Empleado actualizado');
});
