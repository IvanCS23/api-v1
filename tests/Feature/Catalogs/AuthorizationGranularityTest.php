<?php

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Company;
use App\Models\Employe;
use App\Models\Product;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Auditoría de autorización (revisión post-Etapa D)
|--------------------------------------------------------------------------
|
| Decisión: NO se movió authorize() de los controllers a los Form
| Requests para Client/Product/Employe.
|
| - create()/viewAny() en las 3 Policies retornan `true` sin condición:
|   no hay ninguna granularidad real que mover. Estos tests documentan
|   ese comportamiento (cualquier rol autenticado de la empresa puede
|   crear) como un riesgo conocido, no como validación de que sea
|   deseable — es simplemente el estado actual.
| - view()/update()/delete() sí comparan company_id, pero esa comparación
|   es inalcanzable en la práctica: el Global Scope ya convierte un
|   intento cross-tenant en 404 antes de que el controller llegue a
|   invocar la policy. El segundo bloque de tests confirma que ese 404
|   sigue intacto (no se debilitó a 403 ni a ninguna otra cosa).
*/

test('cualquier rol autenticado de la empresa puede crear clientes, productos y empleados (sin granularidad hoy)', function () {
    $company = Company::factory()->create();
    $employeeRoleUser = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Employee]);

    $this->actingAs($employeeRoleUser, 'api')->postJson('/api/clients', [
        'name' => 'Cliente creado por rol employee',
        'email' => 'rol-employee@example.com',
        'rfc' => 'ROL010101AAA',
        'codigo_postal' => '12345',
        'regimen_fiscal' => '601',
        'uso_cfdi' => 'G03',
    ])->assertCreated();

    $this->actingAs($employeeRoleUser, 'api')->postJson('/api/products', [
        'name' => 'Producto creado por rol employee',
        'descripcion' => 'Descripción',
        'precio_unitario' => 10,
        'clave_producto' => 'ROLEMPL1',
    ])->assertCreated();

    $this->actingAs($employeeRoleUser, 'api')->postJson('/api/employees', [
        'email' => 'empleado-rol@example.com',
        'nombre' => 'Empleado',
        'apellido_paterno' => 'Prueba',
        'apellido_materno' => 'Rol',
        'curp' => 'ROLEMPLOYEECURP001',
        'rfc' => 'ROL020202AAA',
        'calle' => 'Calle 1',
        'colonia' => 'Centro',
        'no_exterior' => '1',
        'codigo_postal' => '12345',
        'localidad' => 'Loc',
        'municipio' => 'Mun',
        'estado' => 'CDMX',
        'grupo' => 'G1',
        'sucursal' => 'Matriz',
        'salario_diario' => 500,
        'contrato' => 'Indeterminado',
        'regimen_contratacion' => '02',
        'tipo_jornada' => '01',
        'periodidad_pago' => '05',
        'departamento' => 'Ventas',
        'puesto' => 'Vendedor',
        'no_empleado' => 'ROLEMP01',
        'seguro_social' => '10293847561',
    ])->assertCreated();
});

test('el 404 cross-tenant en update no se debilitó a 403 tras mover la validación a Form Requests', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $userB = User::factory()->create(['company_id' => $companyB->id]);

    $client = Client::factory()->create(['company_id' => $companyA->id]);
    $product = Product::factory()->create(['company_id' => $companyA->id]);
    $employe = Employe::factory()->create(['company_id' => $companyA->id]);

    $this->actingAs($userB, 'api')->putJson("/api/clients/{$client->id}", ['name' => 'x'])
        ->assertNotFound();

    $this->actingAs($userB, 'api')->putJson("/api/products/{$product->id}", ['name' => 'x'])
        ->assertNotFound();

    $this->actingAs($userB, 'api')->putJson("/api/employees/{$employe->id}", ['nombre' => 'x'])
        ->assertNotFound();
});
