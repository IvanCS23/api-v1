<?php

use App\Infrastructure\Billing\Facturapi\FacturapiProvider;
use App\Models\Company;
use App\Models\FacturapiOrganization;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
    ->beforeEach(function () {
        // Ninguna prueba sale a la red: una petición HTTP sin fake falla.
        Http::preventStrayRequests();
        SatCatalogFixture::seed();
    })
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
function facturapiProviderFromConfig(?string $environment = 'test'): FacturapiProvider
{
    return new FacturapiProvider(
        baseUrl: (string) config('services.facturapi.base_url'),
        apiKey: config('services.facturapi.test_key'),
        timeout: (int) config('services.facturapi.timeout'),
        connectTimeout: (int) config('services.facturapi.connect_timeout'),
        environment: $environment,
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

/**
 * Fase 6.31: copia local de una organización lista para producción (CSD
 * vigente, sin pasos pendientes y el correo de contacto sincronizado), como
 * la exige la readiness en Live.
 */
function phase631ProductionReadyOrganization(Company $company): void
{
    $email = "contacto-{$company->id}@example.com";
    $company->forceFill(['email' => $email])->save();

    FacturapiOrganization::withoutGlobalScopes()->where('company_id', $company->id)->firstOrFail()->forceFill([
        'legal_support_email' => $email,
        'has_certificate' => true,
        'certificate_serial_number' => '30001000000500003416',
        'certificate_expires_at' => now()->addYear(),
        'is_production_ready' => true,
        'pending_steps' => [],
    ])->save();
}

/**
 * Fase 6.31: simula una empresa en Live de forma explícita: techo `live`,
 * APP_ENV `production` y su organización con ambiente `live` y una llave
 * Live de prueba. Las pruebas que lo usan deben simular el HTTP.
 */
function phase631MakeLive(int $companyId, string $key = 'sk_live_PHASE_631_SECRET_KEY_0001', Invoice ...$invoices): void
{
    config(['billing.pac_mode' => 'live']);
    app()['env'] = 'production';

    $organization = FacturapiOrganization::withoutGlobalScopes()->where('company_id', $companyId)->first();

    if ($organization !== null) {
        $organization->forceFill(['pac_environment' => 'live'])->setLiveSecretKey($key)->save();
    }

    // Las facturas que la fábrica dejó fijadas en Test pasan a ser de Live.
    DB::table('invoices')->where('company_id', $companyId)->where('pac_environment', 'test')->update(['pac_environment' => 'live']);

    foreach ($invoices as $invoice) {
        $invoice->forceFill(['pac_environment' => 'live'])->syncOriginalAttribute('pac_environment');
    }
}
