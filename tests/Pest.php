<?php

use App\Infrastructure\Billing\Facturapi\FacturapiProvider;
use App\Models\Company;
use App\Models\FacturapiOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SatCatalogFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // Fase 6.26: muestra de los catálogos SAT grandes (ver SatCatalogFixture).
    ->beforeEach(fn () => SatCatalogFixture::seed())
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Fase 6.27: las pruebas de la clase FacturapiProvider (payload, respuestas,
 * descargas) la construyen con la llave de config; la resolución por
 * empresa se prueba aparte (PacProviderResolverTest).
 */
function facturapiProviderFromConfig(): FacturapiProvider
{
    return new FacturapiProvider(
        baseUrl: (string) config('services.facturapi.base_url'),
        apiKey: config('services.facturapi.test_key'),
        timeout: (int) config('services.facturapi.timeout'),
        connectTimeout: (int) config('services.facturapi.connect_timeout'),
        mode: config('billing.pac_mode'),
        stampTimezone: config('billing.facturapi.stamp_timezone'),
    );
}

/**
 * Fase 6.27: en el archivo que lo llama, cada empresa creada queda
 * vinculada a una organización de Facturapi con la llave que tenga
 * `services.facturapi.test_key` en ese momento (como tras
 * billing:facturapi-link). Con la llave en null la empresa queda sin
 * organización. Opt-in por archivo: phpunit.xml no sobrescribe la llave de
 * .env, y el resto de la suite no debe vincular empresas con ella.
 */
function facturapiOrganizationsFromConfigKey(): void
{
    Company::created(function (Company $company): void {
        $key = config('services.facturapi.test_key');

        if (! is_string($key)) {
            return;
        }

        FacturapiOrganization::factory()->testKey($key)->create(['company_id' => $company->id]);
    });
}
