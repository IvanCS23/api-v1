<?php

use App\Enums\QuoteStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Quote;
use App\Models\Scopes\CompanyScope;
use App\Models\User;

test('crear una cotización asigna folio, status draft y company_id automáticamente', function () {
    $company = Company::factory()->create();
    $client = Client::factory()->create(['company_id' => $company->id]);
    $user = User::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user, 'api')->postJson('/api/quotes', [
        'client_id' => $client->id,
        'notes' => 'Cotización de prueba',
    ]);

    $response->assertCreated()
        ->assertJsonPath('folio', 'COT-00000001')
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('company_id', $company->id)
        ->assertJsonPath('client_id', $client->id)
        ->assertJsonPath('items', []);

    $quote = Quote::withoutGlobalScope(CompanyScope::class)->findOrFail($response->json('id'));
    expect($quote->status)->toBe(QuoteStatus::Draft);
});

test('un company_id malicioso en el payload de creación es ignorado', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $client = Client::factory()->create(['company_id' => $companyA->id]);
    $user = User::factory()->create(['company_id' => $companyA->id]);

    $response = $this->actingAs($user, 'api')->postJson('/api/quotes', [
        'client_id' => $client->id,
        'company_id' => $companyB->id,
    ]);

    $response->assertCreated();

    $quote = Quote::withoutGlobalScope(CompanyScope::class)->findOrFail($response->json('id'));
    expect($quote->company_id)->toBe($companyA->id);
});

test('los folios de cotización son consecutivos e independientes por empresa', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $clientA = Client::factory()->create(['company_id' => $companyA->id]);
    $clientB = Client::factory()->create(['company_id' => $companyB->id]);
    $userA = User::factory()->create(['company_id' => $companyA->id]);
    $userB = User::factory()->create(['company_id' => $companyB->id]);

    $quoteA1 = $this->actingAs($userA, 'api')->postJson('/api/quotes', ['client_id' => $clientA->id]);
    $quoteA2 = $this->actingAs($userA, 'api')->postJson('/api/quotes', ['client_id' => $clientA->id]);
    $quoteB1 = $this->actingAs($userB, 'api')->postJson('/api/quotes', ['client_id' => $clientB->id]);

    expect($quoteA1->json('folio'))->toBe('COT-00000001')
        ->and($quoteA2->json('folio'))->toBe('COT-00000002')
        ->and($quoteB1->json('folio'))->toBe('COT-00000001');
});

test('una empresa no puede listar ni ver cotizaciones de otra empresa', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $userB = User::factory()->create(['company_id' => $companyB->id]);

    $quoteA = Quote::factory()->create(['company_id' => $companyA->id]);
    Quote::factory()->count(2)->create(['company_id' => $companyA->id]);
    Quote::factory()->create(['company_id' => $companyB->id]);

    $index = $this->actingAs($userB, 'api')->getJson('/api/quotes');
    $index->assertOk();
    expect($index->json())->toHaveCount(1);

    $this->actingAs($userB, 'api')->getJson("/api/quotes/{$quoteA->id}")->assertNotFound();
});

test('enviar una cotización (draft -> sent) es una edición válida', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'status' => QuoteStatus::Draft]);

    $response = $this->actingAs($user, 'api')->putJson("/api/quotes/{$quote->id}", ['status' => 'sent']);

    $response->assertOk()->assertJsonPath('status', 'sent');
});

test('aprobar una cotización enviada cambia su status a approved', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'status' => QuoteStatus::Sent]);

    $response = $this->actingAs($user, 'api')->putJson("/api/quotes/{$quote->id}", ['status' => 'approved']);

    $response->assertOk()->assertJsonPath('status', 'approved');
});

test('rechazar una cotización enviada cambia su status a rejected y la vuelve de solo lectura', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'status' => QuoteStatus::Sent]);

    $reject = $this->actingAs($user, 'api')->putJson("/api/quotes/{$quote->id}", ['status' => 'rejected']);
    $reject->assertOk()->assertJsonPath('status', 'rejected');

    $retry = $this->actingAs($user, 'api')->putJson("/api/quotes/{$quote->id}", ['notes' => 'ya no debería poder']);
    $retry->assertStatus(422);
});

test('expirar una cotización cambia su status a expired y la vuelve de solo lectura', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'status' => QuoteStatus::Sent]);

    $expire = $this->actingAs($user, 'api')->putJson("/api/quotes/{$quote->id}", ['status' => 'expired']);
    $expire->assertOk()->assertJsonPath('status', 'expired');

    $retry = $this->actingAs($user, 'api')->putJson("/api/quotes/{$quote->id}", ['notes' => 'ya no debería poder']);
    $retry->assertStatus(422);
});

test('una cotización aprobada no puede editarse: solo puede convertirse en venta', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'status' => QuoteStatus::Approved]);

    $response = $this->actingAs($user, 'api')->putJson("/api/quotes/{$quote->id}", ['notes' => 'intento de editar']);

    $response->assertStatus(422);
});

test('el status converted no se puede fijar directamente vía update', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'status' => QuoteStatus::Approved]);

    $response = $this->actingAs($user, 'api')->putJson("/api/quotes/{$quote->id}", ['status' => 'converted']);

    // Ni siquiera llega a evaluarse la regla de "approved es solo lectura":
    // la propia validación del Form Request ya rechaza "converted" como
    // valor permitido para este endpoint.
    $response->assertStatus(422)->assertJsonValidationErrors('status');
});
