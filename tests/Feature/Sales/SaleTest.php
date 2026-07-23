<?php

use App\Enums\SaleStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Sale;
use App\Models\Scopes\CompanyScope;
use App\Models\User;

test('crear una venta asigna folio, status draft y company_id automáticamente', function () {
    $company = Company::factory()->create();
    $client = Client::factory()->create(['company_id' => $company->id]);
    $user = User::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user, 'api')->postJson('/api/sales', [
        'client_id' => $client->id,
        'notes' => 'Venta de prueba',
    ]);

    $response->assertCreated()
        ->assertJsonPath('folio', 'VTA-00000001')
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('company_id', $company->id)
        ->assertJsonPath('client_id', $client->id)
        ->assertJsonPath('items', []);

    $sale = Sale::withoutGlobalScope(CompanyScope::class)->findOrFail($response->json('id'));
    expect($sale->status)->toBe(SaleStatus::Draft)
        ->and((float) $sale->total)->toBe(0.0);
});

test('un company_id malicioso en el payload de creación es ignorado', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $client = Client::factory()->create(['company_id' => $companyA->id]);
    $user = User::factory()->create(['company_id' => $companyA->id]);

    $response = $this->actingAs($user, 'api')->postJson('/api/sales', [
        'client_id' => $client->id,
        'company_id' => $companyB->id,
    ]);

    $response->assertCreated();

    $sale = Sale::withoutGlobalScope(CompanyScope::class)->findOrFail($response->json('id'));
    expect($sale->company_id)->toBe($companyA->id);
});

test('no se puede crear una venta con un cliente de otra empresa', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $foreignClient = Client::factory()->create(['company_id' => $companyB->id]);
    $user = User::factory()->create(['company_id' => $companyA->id]);

    $response = $this->actingAs($user, 'api')->postJson('/api/sales', [
        'client_id' => $foreignClient->id,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('client_id');
});

test('los folios son consecutivos e independientes por empresa', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $clientA = Client::factory()->create(['company_id' => $companyA->id]);
    $clientB = Client::factory()->create(['company_id' => $companyB->id]);
    $userA = User::factory()->create(['company_id' => $companyA->id]);
    $userB = User::factory()->create(['company_id' => $companyB->id]);

    $saleA1 = $this->actingAs($userA, 'api')->postJson('/api/sales', ['client_id' => $clientA->id]);
    $saleA2 = $this->actingAs($userA, 'api')->postJson('/api/sales', ['client_id' => $clientA->id]);
    $saleB1 = $this->actingAs($userB, 'api')->postJson('/api/sales', ['client_id' => $clientB->id]);

    expect($saleA1->json('folio'))->toBe('VTA-00000001')
        ->and($saleA2->json('folio'))->toBe('VTA-00000002')
        ->and($saleB1->json('folio'))->toBe('VTA-00000001'); // empresa distinta, arranca desde 1 otra vez.
});

test('una empresa no puede listar ni ver ventas de otra empresa', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $userB = User::factory()->create(['company_id' => $companyB->id]);

    $saleA = Sale::factory()->create(['company_id' => $companyA->id]);
    Sale::factory()->count(2)->create(['company_id' => $companyA->id]);
    Sale::factory()->create(['company_id' => $companyB->id]);

    $index = $this->actingAs($userB, 'api')->getJson('/api/sales');
    $index->assertOk();
    expect($index->json())->toHaveCount(1);

    $this->actingAs($userB, 'api')->getJson("/api/sales/{$saleA->id}")->assertNotFound();
});

test('cancelar una venta cambia su status y bloquea ediciones posteriores', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $sale = Sale::factory()->create(['company_id' => $company->id, 'status' => SaleStatus::Confirmed]);

    $cancel = $this->actingAs($user, 'api')->putJson("/api/sales/{$sale->id}", ['status' => 'cancelled']);
    $cancel->assertOk()->assertJsonPath('status', 'cancelled');

    $retry = $this->actingAs($user, 'api')->putJson("/api/sales/{$sale->id}", ['notes' => 'ya no debería poder']);
    $retry->assertStatus(422);
});

test('un status inválido en la venta devuelve 422', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $sale = Sale::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user, 'api')->putJson("/api/sales/{$sale->id}", ['status' => 'invoiced']);

    $response->assertStatus(422)->assertJsonValidationErrors('status');
});
