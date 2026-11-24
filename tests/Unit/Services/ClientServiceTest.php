<?php

use App\Models\Client;
use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Services\Catalogs\ClientService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// tests/Pest.php solo extiende TestCase en Feature: aquí se declara a mano.
uses(TestCase::class, RefreshDatabase::class);

/*
 * Sin propiedades en $this para que el analizador del editor (Intelephense)
 * resuelva los tipos: cada prueba crea su empresa y obtiene el servicio con
 * clientServiceForCompany().
 */
function clientServiceForCompany(Company $company): ClientService
{
    app(CurrentTenant::class)->set($company->id);

    return app(ClientService::class);
}

afterEach(function () {
    app(CurrentTenant::class)->clear();
});

test('create guarda el cliente en la empresa actual', function () {
    $company = Company::factory()->create();
    $service = clientServiceForCompany($company);
    // Todos los datos de la factory menos company_id: la empresa la asigna
    // BelongsToCompany desde CurrentTenant.
    $data = collect(Client::factory()->make(['company_id' => $company->id])->getAttributes())
        ->except('company_id')
        ->all();

    $client = $service->create($data);

    expect($client->exists)->toBeTrue()
        ->and($client->company_id)->toBe($company->id)
        ->and($client->name)->toBe($data['name']);
});

test('update modifica el cliente y lo devuelve', function () {
    $company = Company::factory()->create();
    $service = clientServiceForCompany($company);
    $client = Client::factory()->create(['company_id' => $company->id]);

    $updated = $service->update($client, ['name' => 'Cliente Editado']);

    expect($updated->name)->toBe('Cliente Editado')
        ->and($client->fresh()->name)->toBe('Cliente Editado');
});

test('delete elimina el cliente', function () {
    $company = Company::factory()->create();
    $service = clientServiceForCompany($company);
    $client = Client::factory()->create(['company_id' => $company->id]);

    $service->delete($client);

    expect(Client::withoutGlobalScope(CompanyScope::class)->find($client->id))->toBeNull();
});

test('get con un id inexistente lanza ModelNotFoundException', function () {
    $service = clientServiceForCompany(Company::factory()->create());

    $service->get(999999);
})->throws(ModelNotFoundException::class);

test('get con un id no numérico lanza ModelNotFoundException (404), no TypeError', function () {
    $service = clientServiceForCompany(Company::factory()->create());

    $service->get('abc');
})->throws(ModelNotFoundException::class);

test('get no devuelve clientes de otra empresa', function () {
    $service = clientServiceForCompany(Company::factory()->create());
    $other = Client::factory()->create(['company_id' => Company::factory()->create()->id]);

    $service->get($other->id);
})->throws(ModelNotFoundException::class);

test('all devuelve solo los clientes de la empresa actual', function () {
    $company = Company::factory()->create();
    $service = clientServiceForCompany($company);
    $mine = Client::factory()->count(3)->create(['company_id' => $company->id]);
    Client::factory()->create(['company_id' => Company::factory()->create()->id]);

    expect($service->all()->pluck('id')->sort()->values()->all())
        ->toBe($mine->pluck('id')->sort()->values()->all());
});

test('paginated respeta page y perPage y solo cuenta la empresa actual', function () {
    $company = Company::factory()->create();
    $service = clientServiceForCompany($company);
    Client::factory()->count(5)->create(['company_id' => $company->id]);
    Client::factory()->create(['company_id' => Company::factory()->create()->id]);

    $page = $service->paginated(page: 2, perPage: 2);

    expect($page->currentPage())->toBe(2)
        ->and($page->perPage())->toBe(2)
        ->and($page->total())->toBe(5)
        ->and($page->items())->toHaveCount(2);
});

test('paginated limita perPage a 100 y normaliza valores inválidos', function () {
    $service = clientServiceForCompany(Company::factory()->create());

    expect($service->paginated(perPage: 500)->perPage())->toBe(ClientService::MAX_PER_PAGE)
        ->and($service->paginated(perPage: 0)->perPage())->toBe(1)
        ->and($service->paginated(page: 0)->currentPage())->toBe(1)
        ->and($service->paginated()->perPage())->toBe(ClientService::DEFAULT_PER_PAGE);
});
