<?php

use App\Models\Company;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Contrato JSON actual de Clients/Products/Employes (Etapa D)
|--------------------------------------------------------------------------
|
| Este archivo documenta, con assertJsonStructure exacto (no parcial), la
| forma JSON que el frontend consume HOY de estos tres endpoints, ANTES
| de introducir Form Requests/API Resources. Se escribió y corrió contra
| los controllers originales (validación inline, response()->json($model))
| para fijar la línea base; después de refactorizar a Requests/Resources
| debe seguir pasando sin cambios — si algún día deja de pasar, es una
| señal de que el contrato cambió y el frontend podría romperse.
|
| Nota sobre `deleted_at` y la respuesta de STORE: los controllers actuales
| devuelven la instancia recién creada en memoria (`Model::create()`), que
| nunca pasó por un SELECT real, así que columnas nunca tocadas como
| `deleted_at` (SoftDeletes, sin default a nivel de atributo de PHP) NO
| aparecen en esa respuesta puntual — sí aparecen en index/show/update,
| porque esos sí parten de un `findOrFail()`/consulta real. Es el
| comportamiento real y preexistente, no un descuido de este test.
|
*/

test('contrato JSON de clients: index, store, show, update', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    $storeKeys = [
        'id', 'company_id', 'name', 'email', 'rfc', 'codigo_postal',
        'regimen_fiscal', 'uso_cfdi', 'calle', 'no_exterior', 'no_interior',
        'colonia', 'localidad', 'municipio', 'estado', 'pais',
        'created_at', 'updated_at',
    ];
    $fetchedKeys = [...$storeKeys, 'deleted_at'];

    $store = $this->actingAs($user, 'api')->postJson('/api/clients', [
        'name' => 'Contrato Cliente',
        'email' => 'contrato.cliente@example.com',
        'rfc' => 'CCC010101AAA',
        'codigo_postal' => '12345',
        'regimen_fiscal' => '601',
        'uso_cfdi' => 'G03',
        'calle' => 'Calle 1',
        'no_exterior' => '1',
        'no_interior' => '2',
        'colonia' => 'Centro',
        'localidad' => 'Loc',
        'municipio' => 'Mun',
        'estado' => 'CDMX',
        'pais' => 'México',
    ]);
    $store->assertCreated()->assertJsonStructure($storeKeys);
    $id = $store->json('id');

    $this->actingAs($user, 'api')->getJson('/api/clients')
        ->assertOk()
        ->assertJsonStructure(['*' => $fetchedKeys]);

    $this->actingAs($user, 'api')->getJson("/api/clients/{$id}")
        ->assertOk()
        ->assertJsonStructure($fetchedKeys);

    $this->actingAs($user, 'api')->putJson("/api/clients/{$id}", ['name' => 'Contrato Cliente Editado'])
        ->assertOk()
        ->assertJsonStructure($fetchedKeys)
        ->assertJsonPath('name', 'Contrato Cliente Editado');
});

test('contrato JSON de products: index, store, show, update', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    $storeKeys = [
        'id', 'company_id', 'name', 'type', 'no_identificacion', 'descripcion',
        'precio_unitario', 'cuenta_predial', 'clave_producto', 'clave_unidad',
        'objeto_imp', 'no_pedimento', 'impuesto_local', 'iva', 'iva_retenido',
        'ieps', 'isr', 'created_at', 'updated_at',
    ];
    $fetchedKeys = [...$storeKeys, 'deleted_at'];

    $store = $this->actingAs($user, 'api')->postJson('/api/products', [
        'name' => 'Contrato Producto',
        'type' => 'product',
        'no_identificacion' => 'CTR-ID-1',
        'descripcion' => 'Descripción de contrato',
        'precio_unitario' => 10,
        'cuenta_predial' => '123',
        'clave_producto' => 'CTR12345',
        'clave_unidad' => 'H87',
        'objeto_imp' => '02',
        'no_pedimento' => '1',
        'impuesto_local' => 'ninguno',
        'iva' => 16,
        'iva_retenido' => 4,
        'ieps' => 8,
        'isr' => 1.25,
    ]);
    $store->assertCreated()->assertJsonStructure($storeKeys);
    $id = $store->json('id');

    $this->actingAs($user, 'api')->getJson('/api/products')
        ->assertOk()
        ->assertJsonStructure(['*' => $fetchedKeys]);

    $this->actingAs($user, 'api')->getJson("/api/products/{$id}")
        ->assertOk()
        ->assertJsonStructure($fetchedKeys);

    $this->actingAs($user, 'api')->putJson("/api/products/{$id}", ['name' => 'Contrato Producto Editado'])
        ->assertOk()
        ->assertJsonStructure($fetchedKeys)
        ->assertJsonPath('name', 'Contrato Producto Editado');
});

test('contrato JSON de employes: index, store, show, update', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    $storeKeys = [
        'id', 'company_id', 'email', 'nombre', 'apellido_paterno', 'apellido_materno',
        'curp', 'rfc', 'calle', 'colonia', 'no_exterior', 'no_interior',
        'codigo_postal', 'localidad', 'municipio', 'estado', 'grupo', 'sucursal',
        'salario_diario', 'contrato', 'regimen_contratacion', 'tipo_jornada',
        'banco', 'clave_bancaria', 'periodidad_pago', 'departamento', 'puesto',
        'no_empleado', 'seguro_social', 'subcontratacion',
        'created_at', 'updated_at',
    ];
    $fetchedKeys = [...$storeKeys, 'deleted_at'];

    $payload = [
        'email' => 'contrato.empleado@example.com',
        'nombre' => 'Contrato',
        'apellido_paterno' => 'Empleado',
        'apellido_materno' => 'Prueba',
        'curp' => 'CONTRATOCURP000001',
        'rfc' => 'CTE010101AAA',
        'calle' => 'Calle 1',
        'colonia' => 'Centro',
        'no_exterior' => '1',
        'no_interior' => '2',
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
        'banco' => 'BBVA',
        'clave_bancaria' => '123456789012345678',
        'periodidad_pago' => '05',
        'departamento' => 'Ventas',
        'puesto' => 'Vendedor',
        'no_empleado' => 'CTR001',
        'seguro_social' => '11122233344',
        'subcontratacion' => 'ninguna',
    ];

    $store = $this->actingAs($user, 'api')->postJson('/api/employees', $payload);
    $store->assertCreated()->assertJsonStructure($storeKeys);
    $id = $store->json('id');

    $this->actingAs($user, 'api')->getJson('/api/employees')
        ->assertOk()
        ->assertJsonStructure(['*' => $fetchedKeys]);

    $this->actingAs($user, 'api')->getJson("/api/employees/{$id}")
        ->assertOk()
        ->assertJsonStructure($fetchedKeys);

    $this->actingAs($user, 'api')->putJson("/api/employees/{$id}", ['nombre' => 'Contrato Editado'])
        ->assertOk()
        ->assertJsonStructure($fetchedKeys)
        ->assertJsonPath('nombre', 'Contrato Editado');
});
