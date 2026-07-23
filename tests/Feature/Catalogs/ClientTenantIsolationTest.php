<?php

use App\Models\Client;
use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Models\User;

test('index solo devuelve clientes de la empresa del usuario autenticado', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    Client::factory()->count(2)->create(['company_id' => $companyA->id]);
    Client::factory()->count(3)->create(['company_id' => $companyB->id]);

    $user = User::factory()->create(['company_id' => $companyA->id]);

    $response = $this->actingAs($user, 'api')->getJson('/api/clients');

    $response->assertOk();
    expect($response->json())->toHaveCount(2);
});

test('store asigna company_id automáticamente desde el usuario autenticado, no desde el payload', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $companyA->id]);

    $response = $this->actingAs($user, 'api')->postJson('/api/clients', [
        'name' => 'Cliente de prueba',
        'email' => 'cliente@example.com',
        'rfc' => 'XAXX010101000',
        'codigo_postal' => '12345',
        'regimen_fiscal' => '601',
        'uso_cfdi' => 'G03',
        'company_id' => $companyB->id, // intento de inyectar otra empresa vía payload
    ]);

    $response->assertCreated();

    $created = Client::withoutGlobalScope(CompanyScope::class)->findOrFail($response->json('id'));
    expect($created->company_id)->toBe($companyA->id);
});

test('la validación unique de email y rfc es por empresa, no global', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    Client::factory()->create([
        'company_id' => $companyA->id,
        'email' => 'compartido@example.com',
        'rfc' => 'AAA010101AAA',
    ]);

    $userB = User::factory()->create(['company_id' => $companyB->id]);

    $response = $this->actingAs($userB, 'api')->postJson('/api/clients', [
        'name' => 'Cliente de otra empresa',
        'email' => 'compartido@example.com',
        'rfc' => 'AAA010101AAA',
        'codigo_postal' => '54321',
        'regimen_fiscal' => '601',
        'uso_cfdi' => 'G03',
    ]);
    $response->assertCreated();

    $userA = User::factory()->create(['company_id' => $companyA->id]);

    $duplicate = $this->actingAs($userA, 'api')->postJson('/api/clients', [
        'name' => 'Cliente duplicado',
        'email' => 'compartido@example.com',
        'rfc' => 'BBB020202BBB',
        'codigo_postal' => '11111',
        'regimen_fiscal' => '601',
        'uso_cfdi' => 'G03',
    ]);
    $duplicate->assertStatus(422);
    $duplicate->assertJsonValidationErrors('email');
});

test('un usuario no puede ver, editar ni borrar clientes de otra empresa', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $client = Client::factory()->create(['company_id' => $companyA->id]);
    $userB = User::factory()->create(['company_id' => $companyB->id]);

    $this->actingAs($userB, 'api')->getJson("/api/clients/{$client->id}")->assertNotFound();
    $this->actingAs($userB, 'api')->putJson("/api/clients/{$client->id}", ['name' => 'Hackeado'])->assertNotFound();
    $this->actingAs($userB, 'api')->deleteJson("/api/clients/{$client->id}")->assertNotFound();

    expect($client->fresh()->name)->not->toBe('Hackeado');
});

test('la policy deniega el acceso entre empresas aunque se use withoutGlobalScope', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $client = Client::factory()->create(['company_id' => $companyA->id]);
    $userB = User::factory()->create(['company_id' => $companyB->id]);
    $userA = User::factory()->create(['company_id' => $companyA->id]);

    $clientWithoutScope = Client::withoutGlobalScope(CompanyScope::class)->findOrFail($client->id);

    expect($userB->can('view', $clientWithoutScope))->toBeFalse()
        ->and($userB->can('update', $clientWithoutScope))->toBeFalse()
        ->and($userB->can('delete', $clientWithoutScope))->toBeFalse()
        ->and($userA->can('view', $clientWithoutScope))->toBeTrue()
        ->and($userA->can('update', $clientWithoutScope))->toBeTrue()
        ->and($userA->can('delete', $clientWithoutScope))->toBeTrue();
});

test('company_id no se puede modificar manualmente una vez creado el registro', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $client = Client::factory()->create(['company_id' => $companyA->id]);

    $client->company_id = $companyB->id;
    $client->save();

    expect($client->fresh()->company_id)->toBe($companyA->id);
});
