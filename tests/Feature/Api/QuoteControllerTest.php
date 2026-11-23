<?php

use App\Enums\QuoteStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Quote;
use App\Models\Scopes\CompanyScope;
use App\Models\User;

use function Pest\Laravel\actingAs;

/*
 * Contrato HTTP de /api/quotes tras delegar QuoteController en QuoteService:
 * rutas, códigos y mensajes deben seguir siendo los mismos de antes.
 *
 * Sin propiedades en $this y con actingAs() de pest-plugin-laravel para que
 * el analizador del editor (Intelephense) resuelva los tipos.
 */

test('index sin ?page devuelve todas las cotizaciones de la empresa', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    Quote::factory()->count(3)->create(['company_id' => $company->id]);
    Quote::factory()->create(['company_id' => Company::factory()->create()->id]);

    actingAs($user, 'api')->getJson('/api/quotes')
        ->assertOk()
        ->assertJsonCount(3);
});

test('index con ?page devuelve la página pedida', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    Quote::factory()->count(5)->create(['company_id' => $company->id]);
    Quote::factory()->create(['company_id' => Company::factory()->create()->id]);

    actingAs($user, 'api')->getJson('/api/quotes?page=2&per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 5);
});

test('index con ?page usa 15 por página por defecto y limita per_page a 100', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    Quote::factory()->count(3)->create(['company_id' => $company->id]);

    actingAs($user, 'api')->getJson('/api/quotes?page=1')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.per_page', 15);

    actingAs($user, 'api')->getJson('/api/quotes?page=1&per_page=500')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 100);
});

test('store responde 201 con la cotización en Draft', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);

    actingAs($user, 'api')->postJson('/api/quotes', ['client_id' => $client->id])
        ->assertCreated()
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('folio', 'COT-00000001');
});

test('update responde 422 cuando la cotización no es editable', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $quote = Quote::factory()->create([
        'company_id' => $company->id,
        'status' => QuoteStatus::Approved,
        'notes' => 'Original',
    ]);

    actingAs($user, 'api')->putJson("/api/quotes/{$quote->id}", ['notes' => 'Editada'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Esta cotización ya no se puede editar en su estado actual.');

    expect(Quote::withoutGlobalScope(CompanyScope::class)->find($quote->id)->notes)->toBe('Original');
});

test('destroy responde 204 en una cotización editable', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'status' => QuoteStatus::Draft]);

    actingAs($user, 'api')->deleteJson("/api/quotes/{$quote->id}")
        ->assertNoContent();

    expect(Quote::withoutGlobalScope(CompanyScope::class)->find($quote->id))->toBeNull();
});

test('destroy responde 422 cuando la cotización no es editable', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'status' => QuoteStatus::Approved]);

    actingAs($user, 'api')->deleteJson("/api/quotes/{$quote->id}")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Esta cotización ya no se puede eliminar en su estado actual.');

    expect(Quote::withoutGlobalScope(CompanyScope::class)->find($quote->id))->not->toBeNull();
});
