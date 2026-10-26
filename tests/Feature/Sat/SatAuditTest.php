<?php

use App\Models\Client;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
| Fase 6.26: reporte de solo lectura de datos guardados que ya no cumplen
| las reglas SAT, y `fiscal_issues` en los Resources de clientes y productos.
*/

test('sat:audit-catalog-data reporta clientes y productos inválidos sin modificarlos', function () {
    $company = Company::factory()->create();
    $badClient = Client::factory()->create(['company_id' => $company->id, 'rfc' => 'ABC123']);
    Client::factory()->create(['company_id' => $company->id]);
    $badProduct = Product::factory()->create(['company_id' => $company->id, 'clave_producto' => 'dasdsada', 'clave_unidad' => '888', 'objeto_imp' => '01', 'tax_rate_id' => null]);
    Product::factory()->create(['company_id' => $company->id, 'clave_unidad' => 'H87']);

    $writes = 0;
    DB::listen(function ($query) use (&$writes): void {
        if (! preg_match('/^\s*select\b/i', $query->sql)) {
            $writes++;
        }
    });

    $exit = Artisan::call('sat:audit-catalog-data', ['--format' => 'json']);
    $report = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($writes)->toBe(0)
        ->and($report['clients'])->toMatchArray(['total' => 2, 'invalid' => 1])
        ->and($report['clients']['rows'][0])->toMatchArray(['id' => $badClient->id, 'codes' => ['CLIENT_RFC_INVALID'], 'fields' => ['rfc']])
        ->and($report['products'])->toMatchArray(['total' => 2, 'invalid' => 1])
        ->and($report['products']['rows'][0]['id'])->toBe($badProduct->id)
        ->and($report['products']['rows'][0]['codes'])->toBe(['PRODUCT_SAT_KEY_INVALID', 'PRODUCT_UNIT_KEY_INVALID'])
        ->and($report['by_code'])->toBe(['CLIENT_RFC_INVALID' => 1, 'PRODUCT_SAT_KEY_INVALID' => 1, 'PRODUCT_UNIT_KEY_INVALID' => 1]);

    expect($badClient->fresh()->rfc)->toBe('ABC123')
        ->and($badProduct->fresh()->clave_producto)->toBe('dasdsada');
});

test('el reporte nunca imprime el RFC de un cliente', function () {
    $company = Company::factory()->create();
    Client::factory()->create(['company_id' => $company->id, 'rfc' => 'SECRETO99', 'regimen_fiscal' => '999']);

    Artisan::call('sat:audit-catalog-data');
    $table = Artisan::output();
    Artisan::call('sat:audit-catalog-data', ['--format' => 'json']);
    $json = Artisan::output();

    expect($table)->toContain('CLIENT_RFC_INVALID')->not->toContain('SECRETO99')
        ->and($json)->not->toContain('SECRETO99');
});

test('el reporte se puede limitar a una empresa y fallar con --fail-on-issues', function () {
    $clean = Company::factory()->create();
    $dirty = Company::factory()->create();
    Client::factory()->create(['company_id' => $clean->id]);
    Client::factory()->create(['company_id' => $dirty->id, 'codigo_postal' => '12']);

    $this->artisan('sat:audit-catalog-data', ['--company' => $clean->id, '--fail-on-issues' => true])->assertSuccessful();
    $this->artisan('sat:audit-catalog-data', ['--company' => $dirty->id, '--fail-on-issues' => true])->assertFailed();
    $this->artisan('sat:audit-catalog-data', ['--company' => 'x'])->assertExitCode(2);
    $this->artisan('sat:audit-catalog-data', ['--format' => 'csv'])->assertExitCode(2);
});

test('la API de clientes incluye fiscal_issues', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $valid = Client::factory()->create(['company_id' => $company->id]);
    $invalid = Client::factory()->create(['company_id' => $company->id, 'rfc' => 'ABC123', 'uso_cfdi' => 'Z99']);

    $this->actingAs($user, 'api')->getJson("/api/clients/{$valid->id}")->assertOk()->assertJsonPath('fiscal_issues', []);

    $index = collect($this->actingAs($user, 'api')->getJson('/api/clients')->assertOk()->json())->keyBy('id');
    expect(collect($index[$invalid->id]['fiscal_issues'])->pluck('code')->all())->toBe(['CLIENT_RFC_INVALID', 'CLIENT_CFDI_USE_INVALID']);
});

test('la API de productos incluye fiscal_issues con una consulta por catálogo en el índice', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    Product::factory()->count(5)->create(['company_id' => $company->id, 'clave_unidad' => 'H87']);
    $invalid = Product::factory()->create(['company_id' => $company->id, 'clave_producto' => '99999999', 'clave_unidad' => 'h87', 'objeto_imp' => '02']);

    $catalogQueries = 0;
    DB::listen(function ($query) use (&$catalogQueries): void {
        if (str_contains($query->sql, 'sat_product_keys') || str_contains($query->sql, 'sat_unit_keys')) {
            $catalogQueries++;
        }
    });

    $index = collect($this->actingAs($user, 'api')->getJson('/api/products')->assertOk()->json())->keyBy('id');

    expect($catalogQueries)->toBe(2)
        ->and($index)->toHaveCount(6)
        ->and(collect($index[$invalid->id]['fiscal_issues'])->pluck('code')->all())->toBe(['PRODUCT_SAT_KEY_INVALID', 'PRODUCT_TAX_OBJECT_MISMATCH'])
        ->and($index->except($invalid->id)->pluck('fiscal_issues')->unique()->all())->toBe([[]]);

    $this->actingAs($user, 'api')->getJson("/api/products/{$invalid->id}")
        ->assertOk()
        ->assertJsonPath('fiscal_issues.0.code', 'PRODUCT_SAT_KEY_INVALID');
});
