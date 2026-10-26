<?php

use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use App\Services\Sat\SatFiscalValidator;

/*
| Fase 6.26: RFC, código postal, régimen y uso de CFDI de los clientes se
| validan contra los catálogos del SAT y su matriz de compatibilidad.
*/

function fiscalClientPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Cliente Fiscal SA de CV',
        'email' => 'fiscal'.uniqid().'@example.com',
        'rfc' => 'CFI010203AB1',
        'codigo_postal' => '06600',
        'regimen_fiscal' => '601',
        'uso_cfdi' => 'G03',
    ], $overrides);
}

function fiscalUser(): User
{
    return User::factory()->create(['company_id' => Company::factory()->create()->id]);
}

test('acepta persona moral, persona física y los RFC genéricos con 616 y S01', function (array $overrides) {
    $this->actingAs(fiscalUser(), 'api')
        ->postJson('/api/clients', fiscalClientPayload($overrides))
        ->assertCreated();
})->with([
    'moral 601 / G03' => [[]],
    'física 612 / D01' => [['rfc' => 'GODE561231GR8', 'regimen_fiscal' => '612', 'uso_cfdi' => 'D01']],
    'física 605 / CN01' => [['rfc' => 'GODE561231GR8', 'regimen_fiscal' => '605', 'uso_cfdi' => 'CN01']],
    'RSC para moral' => [['regimen_fiscal' => '626', 'uso_cfdi' => 'G01']],
    'genérico nacional' => [['rfc' => 'XAXX010101000', 'regimen_fiscal' => '616', 'uso_cfdi' => 'S01']],
    'genérico extranjero' => [['rfc' => 'XEXX010101000', 'regimen_fiscal' => '616', 'uso_cfdi' => 'S01']],
]);

test('normaliza el RFC y el uso a mayúsculas sin espacios', function () {
    $response = $this->actingAs(fiscalUser(), 'api')
        ->postJson('/api/clients', fiscalClientPayload(['rfc' => ' cfi010203ab1 ', 'uso_cfdi' => 'g03']))
        ->assertCreated();

    expect($response->json('rfc'))->toBe('CFI010203AB1')
        ->and($response->json('uso_cfdi'))->toBe('G03');
});

test('rechaza un RFC con formato inválido', function (string $rfc) {
    $this->actingAs(fiscalUser(), 'api')
        ->postJson('/api/clients', fiscalClientPayload(['rfc' => $rfc]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rfc' => 'formato válido']);
})->with([
    'muy corto' => ['ABC123'],
    '11 caracteres' => ['AAA010101AA'],
    'mes 13' => ['AAA011301AB1'],
    'día 32' => ['AAA010132AB1'],
    'dígitos donde van letras' => ['1AA010101AB1'],
]);

test('rechaza un código postal que no tiene 5 dígitos', function (string $postalCode) {
    $this->actingAs(fiscalUser(), 'api')
        ->postJson('/api/clients', fiscalClientPayload(['codigo_postal' => $postalCode]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['codigo_postal' => '5 dígitos']);
})->with(['1234', 'ABCDE', '123456']);

test('rechaza régimen y uso fuera del catálogo del SAT', function () {
    $this->actingAs(fiscalUser(), 'api')
        ->postJson('/api/clients', fiscalClientPayload(['regimen_fiscal' => '999', 'uso_cfdi' => 'Z01']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['regimen_fiscal' => 'no existe', 'uso_cfdi' => 'no existe']);
});

test('el régimen y el uso deben aplicar al tipo de persona del RFC', function (array $overrides, string $field, string $message) {
    $this->actingAs(fiscalUser(), 'api')
        ->postJson('/api/clients', fiscalClientPayload($overrides))
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field => $message]);
})->with([
    'moral con 605' => [['regimen_fiscal' => '605', 'uso_cfdi' => 'G03'], 'regimen_fiscal', 'no aplica a una persona moral'],
    'física con 601' => [['rfc' => 'GODE561231GR8', 'regimen_fiscal' => '601'], 'regimen_fiscal', 'no aplica a una persona física'],
    'moral con D01' => [['uso_cfdi' => 'D01'], 'uso_cfdi', 'no aplica a una persona moral'],
]);

test('el uso debe ser compatible con el régimen según la matriz del SAT', function () {
    $this->actingAs(fiscalUser(), 'api')
        ->postJson('/api/clients', fiscalClientPayload(['rfc' => 'GODE561231GR8', 'regimen_fiscal' => '612', 'uso_cfdi' => 'CN01']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['uso_cfdi' => 'no es compatible con el régimen fiscal 612']);
});

test('un RFC genérico exige el régimen 616 y el uso S01', function () {
    $this->actingAs(fiscalUser(), 'api')
        ->postJson('/api/clients', fiscalClientPayload(['rfc' => 'XAXX010101000', 'regimen_fiscal' => '612', 'uso_cfdi' => 'G03']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['regimen_fiscal' => '616', 'uso_cfdi' => 'S01']);
});

test('editar sólo datos no fiscales de un cliente inválido no exige corregirlo', function () {
    $user = fiscalUser();
    $client = Client::factory()->create(['company_id' => $user->company_id, 'rfc' => 'ABC123']);

    $this->actingAs($user, 'api')
        ->putJson("/api/clients/{$client->id}", ['name' => 'Nuevo nombre'])
        ->assertOk()
        ->assertJsonPath('name', 'Nuevo nombre');
});

test('editar un dato fiscal de un cliente inválido exige corregir el conjunto', function () {
    $user = fiscalUser();
    $client = Client::factory()->create(['company_id' => $user->company_id, 'rfc' => 'ABC123']);

    $this->actingAs($user, 'api')
        ->putJson("/api/clients/{$client->id}", ['uso_cfdi' => 'G01'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rfc' => 'formato válido']);

    $this->actingAs($user, 'api')
        ->putJson("/api/clients/{$client->id}", ['rfc' => 'ABC010101AB1', 'uso_cfdi' => 'G01'])
        ->assertOk()
        ->assertJsonPath('rfc', 'ABC010101AB1');
});

test('la edición compara contra lo que el cliente ya tenía', function () {
    $user = fiscalUser();
    $client = Client::factory()->create(['company_id' => $user->company_id, 'rfc' => 'GODE561231GR8', 'regimen_fiscal' => '612', 'uso_cfdi' => 'G03']);

    $this->actingAs($user, 'api')
        ->putJson("/api/clients/{$client->id}", ['uso_cfdi' => 'CN01'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['uso_cfdi' => 'régimen fiscal 612']);
});

test('SatFiscalValidator distingue persona moral, física y RFC inválido', function () {
    $validator = new SatFiscalValidator;

    expect($validator->personType('CFI010203AB1'))->toBe(SatFiscalValidator::PERSON_MORAL)
        ->and($validator->personType('gode561231gr8'))->toBe(SatFiscalValidator::PERSON_PHYSICAL)
        ->and($validator->personType('XAXX010101000'))->toBe(SatFiscalValidator::PERSON_PHYSICAL)
        ->and($validator->personType('ÑAB000229AB1'))->toBe(SatFiscalValidator::PERSON_MORAL)
        ->and($validator->personType('AAA010101AA'))->toBeNull()
        ->and($validator->isGenericRfc('xexx010101000'))->toBeTrue();

    expect(collect($validator->clientIssues(['rfc' => 'ABC123', 'codigo_postal' => '1', 'regimen_fiscal' => '000', 'uso_cfdi' => 'X']))->pluck('code')->all())
        ->toBe(['CLIENT_RFC_INVALID', 'CLIENT_POSTAL_CODE_INVALID', 'CLIENT_FISCAL_REGIME_INVALID', 'CLIENT_CFDI_USE_INVALID'])
        ->and($validator->clientIssues(['rfc' => '', 'codigo_postal' => null]))->toBe([]);
});
