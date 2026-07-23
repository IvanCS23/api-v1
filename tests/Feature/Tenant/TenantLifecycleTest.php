<?php

use App\Models\Client;
use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Orden de middleware (Objetivo 1)
|--------------------------------------------------------------------------
|
| Se registran rutas ad-hoc, exclusivas de este archivo, con exactamente
| la misma composición de middleware que las rutas reales de la API
| (grupo "api" -donde vive TenantMiddleware- + "auth:api" + "throttle:60,1"
| a nivel de ruta, igual que en routes/api.php). Cada test crea su propia
| aplicación fresca (Laravel arranca un Router nuevo por test), así que
| estas rutas no contaminan otros archivos de test ni las rutas reales.
|
*/

test('auth:api corre antes que TenantMiddleware: CurrentTenant ya tiene el company_id correcto cuando el handler ejecuta', function () {
    Route::middleware(['api', 'auth:api', 'throttle:60,1'])->get('/api/_test/tenant-probe', function () {
        return response()->json(['tenant_id' => app(CurrentTenant::class)->id()]);
    });

    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user, 'api')->getJson('/api/_test/tenant-probe');

    $response->assertOk();
    // Si TenantMiddleware corriera ANTES de auth:api, $request->user() sería
    // null en ese punto y este valor sería null, no el id de la empresa.
    expect($response->json('tenant_id'))->toBe($company->id);
});

test('dos peticiones consecutivas de empresas distintas no comparten tenant', function () {
    Route::middleware(['api', 'auth:api', 'throttle:60,1'])->get('/api/_test/tenant-probe-2', function () {
        return response()->json(['tenant_id' => app(CurrentTenant::class)->id()]);
    });

    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $userA = User::factory()->create(['company_id' => $companyA->id]);
    $userB = User::factory()->create(['company_id' => $companyB->id]);

    $responseA = $this->actingAs($userA, 'api')->getJson('/api/_test/tenant-probe-2');
    $responseB = $this->actingAs($userB, 'api')->getJson('/api/_test/tenant-probe-2');

    expect($responseA->json('tenant_id'))->toBe($companyA->id)
        ->and($responseB->json('tenant_id'))->toBe($companyB->id);
});

/*
|--------------------------------------------------------------------------
| Limpieza garantizada del contexto (Objetivo 2)
|--------------------------------------------------------------------------
*/

test('CurrentTenant se limpia después de una respuesta exitosa', function () {
    Route::middleware(['api', 'auth:api', 'throttle:60,1'])->get('/api/_test/tenant-success', function () {
        return response()->json(['ok' => true]);
    });

    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    $this->actingAs($user, 'api')->getJson('/api/_test/tenant-success')->assertOk();

    expect(app(CurrentTenant::class)->has())->toBeFalse();
});

test('CurrentTenant se limpia después de una excepción no controlada (500)', function () {
    Route::middleware(['api', 'auth:api', 'throttle:60,1'])->get('/api/_test/tenant-explode', function () {
        throw new RuntimeException('boom');
    });

    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    $this->actingAs($user, 'api')->getJson('/api/_test/tenant-explode')->assertStatus(500);

    expect(app(CurrentTenant::class)->has())->toBeFalse();
});

test('CurrentTenant se limpia después de un 404', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    $this->actingAs($user, 'api')->getJson('/api/clients/999999')->assertNotFound();

    expect(app(CurrentTenant::class)->has())->toBeFalse();
});

test('CurrentTenant se limpia después de un 403', function () {
    Route::middleware(['api', 'auth:api', 'throttle:60,1'])->get('/api/_test/tenant-forbidden', function () {
        abort(403, 'nope');
    });

    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    $this->actingAs($user, 'api')->getJson('/api/_test/tenant-forbidden')->assertForbidden();

    expect(app(CurrentTenant::class)->has())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Fail closed (Objetivo 3 / Objetivo 5 #5 y #9)
|--------------------------------------------------------------------------
*/

test('sin tenant activo, una consulta nunca devuelve todos los registros: devuelve cero', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    Client::factory()->count(2)->create(['company_id' => $companyA->id]);
    Client::factory()->count(3)->create(['company_id' => $companyB->id]);

    app(CurrentTenant::class)->clear();

    expect(Client::count())->toBe(0);
});

test('withoutGlobalScope permite acceso administrativo explícito a todas las empresas', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    Client::factory()->count(2)->create(['company_id' => $companyA->id]);
    Client::factory()->count(3)->create(['company_id' => $companyB->id]);

    app(CurrentTenant::class)->set($companyA->id);

    expect(Client::count())->toBe(2)
        ->and(Client::withoutGlobalScope(CompanyScope::class)->count())->toBe(5);
});

/*
|--------------------------------------------------------------------------
| Inmutabilidad y creación sin tenant (Objetivo 4)
|--------------------------------------------------------------------------
*/

test('crear sin tenant activo y sin company_id explícito falla de forma ruidosa, nunca guarda NULL en silencio', function () {
    app(CurrentTenant::class)->clear();

    expect(fn () => Client::create([
        'name' => 'Sin tenant',
        'email' => 'sin-tenant@example.com',
        'rfc' => 'AAA010101AAA',
        'codigo_postal' => '12345',
        'regimen_fiscal' => '601',
        'uso_cfdi' => 'G03',
        'calle' => 'Calle 1',
        'estado' => 'CDMX',
        'pais' => 'México',
    ]))->toThrow(RuntimeException::class);
});

test('un company_id explícito (factory/seeder de confianza) se respeta al crear, sin necesitar tenant activo', function () {
    app(CurrentTenant::class)->clear();

    $company = Company::factory()->create();

    // Company::factory() en el paso anterior sí requiere tenant? No: Company
    // no usa BelongsToCompany, así que no aplica el guard. Client sí lo usa,
    // y aquí se le da company_id explícito, por lo que el guard no debe
    // dispararse aunque CurrentTenant siga vacío.
    $client = Client::factory()->create(['company_id' => $company->id]);

    expect($client->company_id)->toBe($company->id);
});
