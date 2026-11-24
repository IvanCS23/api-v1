<?php

use App\Models\Company;
use App\Models\Product;
use App\Models\User;

use function Pest\Laravel\actingAs;

/*
 * GET /api/products con ?page: respuesta paginada (data/links/meta) con
 * fiscal_issues en cada producto. Sin ?page el contrato (arreglo plano)
 * lo cubren ApiContractTest, ProductTenantIsolationTest y SatAuditTest.
 */

test('index con ?page devuelve la página pedida con fiscal_issues', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    Product::factory()->count(5)->create(['company_id' => $company->id]);
    Product::factory()->create(['company_id' => Company::factory()->create()->id]);

    actingAs($user, 'api')->getJson('/api/products?page=2&per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 5)
        ->assertJsonStructure(['data' => [['id', 'fiscal_issues']], 'links', 'meta']);
});

test('index con ?page usa 15 por página por defecto', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    Product::factory()->count(3)->create(['company_id' => $company->id]);

    actingAs($user, 'api')->getJson('/api/products?page=1')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.per_page', 15);
});

test('index con per_page=500 lo limita a 100', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    actingAs($user, 'api')->getJson('/api/products?page=1&per_page=500')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 100);
});
