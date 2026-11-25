<?php

use App\Models\Company;
use App\Models\Employe;
use App\Models\Scopes\CompanyScope;
use App\Services\Catalogs\EmployeService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// tests/Pest.php solo extiende TestCase en Feature: aquí se declara a mano.
uses(TestCase::class, RefreshDatabase::class);

/*
 * Sin propiedades en $this para que el analizador del editor (Intelephense)
 * resuelva los tipos: cada prueba crea su empresa y obtiene el servicio con
 * employeServiceForCompany().
 */
function employeServiceForCompany(Company $company): EmployeService
{
    app(CurrentTenant::class)->set($company->id);

    return app(EmployeService::class);
}

afterEach(function () {
    app(CurrentTenant::class)->clear();
});

test('create guarda el empleado en la empresa actual', function () {
    $company = Company::factory()->create();
    $service = employeServiceForCompany($company);
    // Todos los datos de la factory menos company_id: la empresa la asigna
    // BelongsToCompany desde CurrentTenant.
    $data = collect(Employe::factory()->make(['company_id' => $company->id])->getAttributes())
        ->except('company_id')
        ->all();

    $employe = $service->create($data);

    expect($employe->exists)->toBeTrue()
        ->and($employe->company_id)->toBe($company->id)
        ->and($employe->curp)->toBe($data['curp']);
});

test('update modifica el empleado y lo devuelve', function () {
    $company = Company::factory()->create();
    $service = employeServiceForCompany($company);
    $employe = Employe::factory()->create(['company_id' => $company->id]);

    $updated = $service->update($employe, ['puesto' => 'Gerente']);

    expect($updated->puesto)->toBe('Gerente')
        ->and($employe->fresh()->puesto)->toBe('Gerente');
});

test('delete elimina el empleado', function () {
    $company = Company::factory()->create();
    $service = employeServiceForCompany($company);
    $employe = Employe::factory()->create(['company_id' => $company->id]);

    $service->delete($employe);

    expect(Employe::withoutGlobalScope(CompanyScope::class)->find($employe->id))->toBeNull();
});

test('get con un id inexistente lanza ModelNotFoundException', function () {
    $service = employeServiceForCompany(Company::factory()->create());

    $service->get(999999);
})->throws(ModelNotFoundException::class);

test('get con un id no numérico lanza ModelNotFoundException (404), no TypeError', function () {
    $service = employeServiceForCompany(Company::factory()->create());

    $service->get('abc');
})->throws(ModelNotFoundException::class);

test('get no devuelve empleados de otra empresa', function () {
    $service = employeServiceForCompany(Company::factory()->create());
    $other = Employe::factory()->create(['company_id' => Company::factory()->create()->id]);

    $service->get($other->id);
})->throws(ModelNotFoundException::class);

test('all devuelve solo los empleados de la empresa actual', function () {
    $company = Company::factory()->create();
    $service = employeServiceForCompany($company);
    $mine = Employe::factory()->count(3)->create(['company_id' => $company->id]);
    Employe::factory()->create(['company_id' => Company::factory()->create()->id]);

    expect($service->all()->pluck('id')->sort()->values()->all())
        ->toBe($mine->pluck('id')->sort()->values()->all());
});

test('paginated respeta page y perPage y solo cuenta la empresa actual', function () {
    $company = Company::factory()->create();
    $service = employeServiceForCompany($company);
    Employe::factory()->count(5)->create(['company_id' => $company->id]);
    Employe::factory()->create(['company_id' => Company::factory()->create()->id]);

    $page = $service->paginated(page: 2, perPage: 2);

    expect($page->currentPage())->toBe(2)
        ->and($page->perPage())->toBe(2)
        ->and($page->total())->toBe(5)
        ->and($page->items())->toHaveCount(2);
});

test('paginated limita perPage a 100 y normaliza valores inválidos', function () {
    $service = employeServiceForCompany(Company::factory()->create());

    expect($service->paginated(perPage: 500)->perPage())->toBe(EmployeService::MAX_PER_PAGE)
        ->and($service->paginated(perPage: 0)->perPage())->toBe(1)
        ->and($service->paginated(page: 0)->currentPage())->toBe(1)
        ->and($service->paginated()->perPage())->toBe(EmployeService::DEFAULT_PER_PAGE);
});
