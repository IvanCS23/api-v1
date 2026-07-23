<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| erp:backfill-company-id — vigencia tras la Etapa C
|--------------------------------------------------------------------------
|
| Este comando se creó en el Sprint 1.1 para poblar company_id mientras la
| columna era nullable en clients/products/employes. En la Etapa C se
| ejecutó una única vez contra la base de datos real y, acto seguido, se
| migró company_id a NOT NULL + índices únicos por empresa en las tres
| tablas. Desde entonces es estructuralmente imposible volver a producir
| una fila "huérfana" (company_id NULL) en esas tablas: el motor de base
| de datos la rechaza antes de que el comando pudiera actuar sobre ella.
|
| Por eso los escenarios originales (huérfanos con 0/1/varias empresas,
| --company, --dry-run, no-sobrescritura, ejecución repetida) ya no son
| reproducibles y se retiraron. El comando se conserva tal cual (puede
| servir para otras tablas en el futuro, o si algún día se reintroduce
| una columna nullable de este tipo); estas pruebas verifican que hoy es
| inofensivo y que la garantía NOT NULL que lo volvió innecesario
| realmente está vigente en las tres tablas.
|
*/

test('sin huérfanos posibles, el comando no hace nada y termina en éxito', function () {
    $this->artisan('erp:backfill-company-id')
        ->assertExitCode(0);
});

test('la base de datos ya no permite company_id NULL en clients, products ni employes', function () {
    $now = now();

    expect(fn () => DB::table('clients')->insert([
        'name' => 'Cliente sin empresa', 'email' => 'sin-empresa@example.com', 'rfc' => 'AAA010101AAA',
        'codigo_postal' => '12345', 'regimen_fiscal' => '601', 'uso_cfdi' => 'G03',
        'calle' => 'Calle 1', 'estado' => 'CDMX', 'pais' => 'México',
        'created_at' => $now, 'updated_at' => $now,
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('products')->insert([
        'name' => 'Producto sin empresa', 'descripcion' => 'Descripción', 'precio_unitario' => 10,
        'clave_producto' => 'SINEMPR1', 'iva' => 16,
        'created_at' => $now, 'updated_at' => $now,
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('employes')->insert([
        'email' => 'sin-empresa-emp@example.com', 'nombre' => 'Juan', 'apellido_paterno' => 'Pérez', 'apellido_materno' => 'López',
        'curp' => 'CURPSINEMPRESA0001', 'rfc' => 'BBB020202BBB', 'calle' => 'Calle 1', 'colonia' => 'Centro',
        'no_exterior' => '1', 'codigo_postal' => '12345', 'localidad' => 'Loc', 'municipio' => 'Mun', 'estado' => 'CDMX',
        'grupo' => 'G1', 'sucursal' => 'Matriz', 'salario_diario' => 500, 'contrato' => 'Indeterminado',
        'regimen_contratacion' => '02', 'tipo_jornada' => '01', 'periodidad_pago' => '05',
        'departamento' => 'Ventas', 'puesto' => 'Vendedor', 'no_empleado' => 'SINEMP01', 'seguro_social' => '12345678901',
        'created_at' => $now, 'updated_at' => $now,
    ]))->toThrow(QueryException::class);
});
