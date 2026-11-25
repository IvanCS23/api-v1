<?php

use App\Enums\SaleStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Sale;
use App\Models\User;
use App\Services\Sales\SaleService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// tests/Pest.php solo extiende TestCase en Feature: aquí se declara a mano.
uses(TestCase::class, RefreshDatabase::class);

/*
 * El contrato HTTP de GET /api/sales (filtros, búsqueda por cliente, 422 con
 * filtros inválidos) lo cubre SaleListingAndTaxRatesTest; aquí se prueba el
 * servicio directamente.
 */
function saleServiceForCompany(Company $company): SaleService
{
    app(CurrentTenant::class)->set($company->id);

    return app(SaleService::class);
}

afterEach(function () {
    app(CurrentTenant::class)->clear();
});

test('search sin filtros pagina 20 por defecto, solo la empresa actual', function () {
    $company = Company::factory()->create();
    $service = saleServiceForCompany($company);
    Sale::factory()->count(3)->create(['company_id' => $company->id]);
    Sale::factory()->create(['company_id' => Company::factory()->create()->id]);

    $page = $service->search([]);

    expect($page->perPage())->toBe(SaleService::DEFAULT_PER_PAGE)
        ->and($page->total())->toBe(3);
});

test('search filtra por status y respeta per_page', function () {
    $company = Company::factory()->create();
    $service = saleServiceForCompany($company);
    Sale::factory()->count(3)->create(['company_id' => $company->id, 'status' => SaleStatus::Draft]);
    $confirmed = Sale::factory()->create(['company_id' => $company->id, 'status' => SaleStatus::Confirmed]);

    $byStatus = $service->search(['status' => SaleStatus::Confirmed->value]);
    $small = $service->search(['per_page' => 2]);

    expect($byStatus->total())->toBe(1)
        ->and($byStatus->items()[0]->id)->toBe($confirmed->id)
        ->and($small->perPage())->toBe(2)
        ->and($small->items())->toHaveCount(2)
        ->and($small->total())->toBe(4);
});

test('search busca por folio, nombre o RFC del cliente', function () {
    $company = Company::factory()->create();
    $service = saleServiceForCompany($company);
    $acme = Client::factory()->create(['company_id' => $company->id, 'name' => 'Acme Industrial', 'rfc' => 'AIN010101AAA']);
    $sale = Sale::factory()->create(['company_id' => $company->id, 'client_id' => $acme->id]);
    Sale::factory()->count(2)->create(['company_id' => $company->id]);

    foreach (['Acme', 'AIN010101', $sale->folio] as $search) {
        $page = $service->search(['search' => $search]);

        expect($page->total())->toBe(1)
            ->and($page->items()[0]->id)->toBe($sale->id)
            ->and($page->items()[0]->relationLoaded('client'))->toBeTrue();
    }
});

test('create deja la venta en Draft con folio y created_by asignados', function () {
    $company = Company::factory()->create();
    $service = saleServiceForCompany($company);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $author = User::factory()->create(['company_id' => $company->id]);

    $sale = $service->create(['client_id' => $client->id, 'notes' => 'Prueba'], $author);

    expect($sale->status)->toBe(SaleStatus::Draft)
        ->and($sale->folio)->toBe('VTA-00000001')
        ->and($sale->created_by)->toBe($author->id)
        ->and($sale->company_id)->toBe($company->id)
        ->and($sale->relationLoaded('items'))->toBeTrue();
});

test('create ignora status y folio que vengan en los datos', function () {
    $company = Company::factory()->create();
    $service = saleServiceForCompany($company);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $author = User::factory()->create(['company_id' => $company->id]);

    $sale = $service->create([
        'client_id' => $client->id,
        'status' => SaleStatus::Confirmed,
        'folio' => 'VTA-99999999',
    ], $author);

    expect($sale->status)->toBe(SaleStatus::Draft)
        ->and($sale->folio)->toBe('VTA-00000001');
});
