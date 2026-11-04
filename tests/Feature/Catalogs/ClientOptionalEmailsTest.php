<?php

use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.30: dos correos opcionales del cliente, además del principal.
 * Opcionales, validados y sin unicidad.
 */
/** @return array{Company, User} */
function phase630ClientContext(): array
{
    $company = Company::factory()->create();

    return [$company, User::factory()->create(['company_id' => $company->id])];
}

function phase630ClientPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Cliente Correos',
        'email' => 'principal@example.com',
        'rfc' => 'CCO010101AAA',
        'codigo_postal' => '06000',
        'regimen_fiscal' => '601',
        'uso_cfdi' => 'G03',
    ], $overrides);
}

test('la migración agrega email_optional_1 y email_optional_2 a clients', function () {
    expect(Schema::hasColumns('clients', ['email_optional_1', 'email_optional_2']))->toBeTrue();
});

test('el alta guarda los correos opcionales y la API los devuelve', function () {
    [, $user] = phase630ClientContext();

    $this->actingAs($user, 'api')
        ->postJson('/api/clients', phase630ClientPayload(['email_optional_1' => 'contador@example.com', 'email_optional_2' => 'compras@example.com']))
        ->assertCreated()
        ->assertJsonPath('email_optional_1', 'contador@example.com')
        ->assertJsonPath('email_optional_2', 'compras@example.com');
});

test('los correos opcionales son opcionales y un texto vacío los deja en null', function () {
    [, $user] = phase630ClientContext();

    $id = $this->actingAs($user, 'api')
        ->postJson('/api/clients', phase630ClientPayload(['email_optional_1' => '']))
        ->assertCreated()
        ->assertJsonPath('email_optional_1', null)
        ->assertJsonPath('email_optional_2', null)
        ->json('id');

    $this->actingAs($user, 'api')->putJson("/api/clients/{$id}", ['email_optional_2' => 'nuevo@example.com'])->assertOk()->assertJsonPath('email_optional_2', 'nuevo@example.com');
    $this->actingAs($user, 'api')->putJson("/api/clients/{$id}", ['email_optional_2' => ''])->assertOk()->assertJsonPath('email_optional_2', null);
});

test('un correo opcional con formato inválido se rechaza', function (string $field) {
    [, $user] = phase630ClientContext();

    $this->actingAs($user, 'api')
        ->postJson('/api/clients', phase630ClientPayload([$field => 'no-es-correo']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with(['email_optional_1', 'email_optional_2']);

test('los correos opcionales no son únicos: dos clientes comparten el del contador', function () {
    [$company, $user] = phase630ClientContext();
    Client::factory()->create(['company_id' => $company->id, 'email_optional_1' => 'contador@example.com']);

    $this->actingAs($user, 'api')
        ->postJson('/api/clients', phase630ClientPayload(['email_optional_1' => 'contador@example.com']))
        ->assertCreated();
});
