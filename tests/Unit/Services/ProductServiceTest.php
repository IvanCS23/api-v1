<?php

use App\Enums\TaxFactorType;
use App\Enums\TaxType;
use App\Models\Company;
use App\Models\Product;
use App\Models\TaxRate;
use App\Services\Catalogs\ProductService;
use App\Services\Taxes\TaxSpec;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// tests/Pest.php solo extiende TestCase en Feature: aquí se declara a mano.
uses(TestCase::class, RefreshDatabase::class);

/*
 * Sin propiedades en $this para que el analizador del editor (Intelephense)
 * resuelva los tipos: cada prueba crea su empresa y obtiene el servicio con
 * productServiceForCompany().
 */
function productServiceForCompany(Company $company): ProductService
{
    app(CurrentTenant::class)->set($company->id);

    return app(ProductService::class);
}

/** @return array<string, mixed> */
function productServiceTestData(array $overrides = []): array
{
    return [
        'name' => 'Asesoría',
        'descripcion' => 'Honorarios',
        'precio_unitario' => 1000,
        'clave_producto' => '43232408',
        'clave_unidad' => 'E48',
        'objeto_imp' => '02',
        ...$overrides,
    ];
}

/** @return list<TaxSpec> IVA 16 % trasladado + IVA 10.6667 % retenido */
function productServiceTestSpecs(): array
{
    return [
        TaxSpec::fromArray(['tax' => '002', 'kind' => 'traslado', 'factor' => 'tasa', 'rate' => 0.16]),
        TaxSpec::fromArray(['tax' => '002', 'kind' => 'retencion', 'factor' => 'tasa', 'rate' => 0.106667]),
    ];
}

afterEach(function () {
    app(CurrentTenant::class)->clear();
});

test('create con taxes guarda los impuestos del producto', function () {
    $service = productServiceForCompany(Company::factory()->create());

    $product = $service->create(productServiceTestData(), productServiceTestSpecs());

    expect($product->exists)->toBeTrue()
        ->and($product->relationLoaded('taxes'))->toBeTrue()
        ->and($product->taxes)->toHaveCount(2)
        ->and(DB::table('product_taxes')->where('product_id', $product->id)->count())->toBe(2);
});

test('create con tax_rate_id deja un solo impuesto tomado de la tasa', function () {
    $service = productServiceForCompany(Company::factory()->create());
    $iva = TaxRate::factory()->create(['code' => '002', 'rate' => 0.16, 'tax_type' => TaxType::Traslado, 'factor_type' => TaxFactorType::Tasa]);

    $product = $service->create(productServiceTestData(['tax_rate_id' => $iva->id]), null, taxRateIdProvided: true);

    expect($product->taxes)->toHaveCount(1)
        ->and($product->taxes->first()->tax)->toBe('002')
        ->and((float) $product->taxes->first()->rate)->toBe(0.16);
});

test('update con taxes reemplaza los impuestos anteriores', function () {
    $service = productServiceForCompany(Company::factory()->create());
    $product = $service->create(productServiceTestData(), productServiceTestSpecs());

    $iva = [TaxSpec::fromArray(['tax' => '002', 'kind' => 'traslado', 'factor' => 'tasa', 'rate' => 0.16])];
    $updated = $service->update($product, ['name' => 'Otro'], $iva);

    expect($updated->name)->toBe('Otro')
        ->and($updated->taxes)->toHaveCount(1)
        ->and(DB::table('product_taxes')->where('product_id', $product->id)->count())->toBe(1);
});

test('update con tax_rate_id null deja el producto sin impuestos', function () {
    $service = productServiceForCompany(Company::factory()->create());
    $product = $service->create(productServiceTestData(), productServiceTestSpecs());

    $updated = $service->update($product, ['tax_rate_id' => null], null, taxRateIdProvided: true);

    expect($updated->taxes)->toHaveCount(0)
        ->and(DB::table('product_taxes')->where('product_id', $product->id)->count())->toBe(0);
});

test('update sin taxes ni tax_rate_id no toca los impuestos', function () {
    $service = productServiceForCompany(Company::factory()->create());
    $product = $service->create(productServiceTestData(), productServiceTestSpecs());

    $updated = $service->update($product->fresh(), ['name' => 'Otro']);

    expect($updated->name)->toBe('Otro')
        ->and(DB::table('product_taxes')->where('product_id', $product->id)->count())->toBe(2);
});

test('get con un id inexistente lanza ModelNotFoundException', function () {
    $service = productServiceForCompany(Company::factory()->create());

    $service->get(999999);
})->throws(ModelNotFoundException::class);

test('get no devuelve productos de otra empresa', function () {
    $service = productServiceForCompany(Company::factory()->create());
    $other = Product::factory()->create(['company_id' => Company::factory()->create()->id]);

    $service->get($other->id);
})->throws(ModelNotFoundException::class);

test('paginated respeta page y perPage y solo cuenta la empresa actual', function () {
    $company = Company::factory()->create();
    $service = productServiceForCompany($company);
    Product::factory()->count(5)->create(['company_id' => $company->id]);
    Product::factory()->create(['company_id' => Company::factory()->create()->id]);

    $page = $service->paginated(page: 2, perPage: 2);

    expect($page->currentPage())->toBe(2)
        ->and($page->perPage())->toBe(2)
        ->and($page->total())->toBe(5)
        ->and($page->items())->toHaveCount(2);
});

test('paginated limita perPage a 100 y normaliza valores inválidos', function () {
    $service = productServiceForCompany(Company::factory()->create());

    expect($service->paginated(perPage: 500)->perPage())->toBe(ProductService::MAX_PER_PAGE)
        ->and($service->paginated(perPage: 0)->perPage())->toBe(1)
        ->and($service->paginated(page: 0)->currentPage())->toBe(1)
        ->and($service->paginated()->perPage())->toBe(ProductService::DEFAULT_PER_PAGE);
});
