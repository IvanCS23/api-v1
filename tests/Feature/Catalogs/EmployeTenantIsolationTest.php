<?php

use App\Models\Company;
use App\Models\Employe;
use App\Models\Scopes\CompanyScope;
use App\Models\User;

function employeStorePayload(array $overrides = []): array
{
    return array_merge([
        'email' => 'empleado'.uniqid().'@example.com',
        'nombre' => 'Juan',
        'apellido_paterno' => 'Pérez',
        'apellido_materno' => 'López',
        'curp' => strtoupper(substr(uniqid('C'), 0, 18)),
        'rfc' => strtoupper(substr(uniqid('R'), 0, 13)),
        'calle' => 'Calle 1',
        'colonia' => 'Centro',
        'no_exterior' => '10',
        'codigo_postal' => '12345',
        'localidad' => 'Localidad',
        'municipio' => 'Municipio',
        'estado' => 'CDMX',
        'grupo' => 'Grupo 1',
        'sucursal' => 'Matriz',
        'salario_diario' => 500,
        'contrato' => 'Indeterminado',
        'regimen_contratacion' => '02',
        'tipo_jornada' => '01',
        'periodidad_pago' => '05',
        'departamento' => 'Ventas',
        'puesto' => 'Vendedor',
        'no_empleado' => strtoupper(uniqid('EMP', false)),
        'seguro_social' => (string) random_int(10000000000, 99999999999),
    ], $overrides);
}

test('index solo devuelve empleados de la empresa del usuario autenticado', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    Employe::factory()->count(2)->create(['company_id' => $companyA->id]);
    Employe::factory()->count(3)->create(['company_id' => $companyB->id]);

    $user = User::factory()->create(['company_id' => $companyA->id]);

    $response = $this->actingAs($user, 'api')->getJson('/api/employees');

    $response->assertOk();
    expect($response->json())->toHaveCount(2);
});

test('store asigna company_id automáticamente desde el usuario autenticado, no desde el payload', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $companyA->id]);

    $response = $this->actingAs($user, 'api')->postJson('/api/employees', employeStorePayload([
        'company_id' => $companyB->id, // intento de inyectar otra empresa vía payload
    ]));

    $response->assertCreated();

    $created = Employe::withoutGlobalScope(CompanyScope::class)->findOrFail($response->json('id'));
    expect($created->company_id)->toBe($companyA->id);
});

test('la validación unique de email y curp es por empresa, no global', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $sharedEmail = 'compartido@example.com';
    $sharedCurp = strtoupper(substr(uniqid('C'), 0, 18));

    Employe::factory()->create(['company_id' => $companyA->id, 'email' => $sharedEmail, 'curp' => $sharedCurp]);

    $userB = User::factory()->create(['company_id' => $companyB->id]);

    $response = $this->actingAs($userB, 'api')->postJson('/api/employees', employeStorePayload([
        'email' => $sharedEmail,
        'curp' => $sharedCurp,
    ]));
    $response->assertCreated();

    $userA = User::factory()->create(['company_id' => $companyA->id]);

    $duplicate = $this->actingAs($userA, 'api')->postJson('/api/employees', employeStorePayload([
        'email' => $sharedEmail,
        'curp' => $sharedCurp,
    ]));
    $duplicate->assertStatus(422);
    $duplicate->assertJsonValidationErrors(['email', 'curp']);
});

test('un usuario no puede ver, editar ni borrar empleados de otra empresa', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $employe = Employe::factory()->create(['company_id' => $companyA->id]);
    $userB = User::factory()->create(['company_id' => $companyB->id]);

    $this->actingAs($userB, 'api')->getJson("/api/employees/{$employe->id}")->assertNotFound();
    $this->actingAs($userB, 'api')->putJson("/api/employees/{$employe->id}", ['nombre' => 'Hackeado'])->assertNotFound();
    $this->actingAs($userB, 'api')->deleteJson("/api/employees/{$employe->id}")->assertNotFound();

    expect($employe->fresh()->nombre)->not->toBe('Hackeado');
});

test('la policy deniega el acceso entre empresas aunque se use withoutGlobalScope', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $employe = Employe::factory()->create(['company_id' => $companyA->id]);
    $userB = User::factory()->create(['company_id' => $companyB->id]);
    $userA = User::factory()->create(['company_id' => $companyA->id]);

    $employeWithoutScope = Employe::withoutGlobalScope(CompanyScope::class)->findOrFail($employe->id);

    expect($userB->can('view', $employeWithoutScope))->toBeFalse()
        ->and($userB->can('update', $employeWithoutScope))->toBeFalse()
        ->and($userB->can('delete', $employeWithoutScope))->toBeFalse()
        ->and($userA->can('view', $employeWithoutScope))->toBeTrue()
        ->and($userA->can('update', $employeWithoutScope))->toBeTrue()
        ->and($userA->can('delete', $employeWithoutScope))->toBeTrue();
});

test('company_id no se puede modificar manualmente una vez creado el registro', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $employe = Employe::factory()->create(['company_id' => $companyA->id]);

    $employe->company_id = $companyB->id;
    $employe->save();

    expect($employe->fresh()->company_id)->toBe($companyA->id);
});
