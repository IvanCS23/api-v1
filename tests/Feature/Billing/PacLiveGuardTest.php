<?php

use App\Contracts\Billing\PacProviderResolver;
use App\Data\Billing\PacInvoiceRequest;
use App\Exceptions\Billing\PacConfigurationException;
use App\Models\Company;
use App\Models\FacturapiOrganization;
use App\Models\FacturapiOrganizationAudit;
use App\Models\Invoice;
use App\Support\Billing\PacCredentialGuard;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Fase 6.31 (3): guard y resolver por ambiente de la empresa, paro de
 * emergencia y captura de la llave Live.
 */
const PHASE_631_LIVE_KEY = 'sk_live_PHASE_631_SECRET_KEY_0001';
const PHASE_631_PROMPT = 'Pega la llave Live (sk_live_…); no se mostrará';

beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'billing.pac_mode' => 'test',
    ]);
    Http::preventStrayRequests();
});

function phase631LiveCompany(): Company
{
    $company = Company::factory()->withFacturapiOrganization('sk_test_PHASE_631_TEST_KEY_0001')->create();
    app(CurrentTenant::class)->set($company->id);

    return $company;
}

function phase631OrganizationOf(Company $company): FacturapiOrganization
{
    return FacturapiOrganization::withoutGlobalScopes()->where('company_id', $company->id)->firstOrFail();
}

/** @return array<string, Closure> */
function phase631ProviderCalls(PacProviderResolver $resolver, Company $company): array
{
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    $provider = $resolver->forCompany($company->id);
    $request = new PacInvoiceRequest($invoice, 'erp-invoice:1:1:v1', 'company-1-invoice-1');

    return [
        'retrieveInvoice' => fn () => $provider->retrieveInvoice('inv_1'),
        'findInvoiceByExternalId' => fn () => $provider->findInvoiceByExternalId('x'),
        'findInvoicesBySeriesFolio' => fn () => $provider->findInvoicesBySeriesFolio('FAC', 1),
        'createDraftInvoice' => fn () => $provider->createDraftInvoice($request),
        'stampDraftInvoice' => fn () => $provider->stampDraftInvoice('inv_1'),
        'cancelInvoice' => fn () => $provider->cancelInvoice('inv_1', '02', null),
        'sendInvoiceEmail' => fn () => $provider->sendInvoiceEmail('inv_1', ['a@example.com']),
        'downloadXml' => fn () => $provider->downloadXml('inv_1'),
        'downloadCancellationReceiptXml' => fn () => $provider->downloadCancellationReceiptXml('inv_1'),
        'currentOrganization' => fn () => $provider->currentOrganization(),
    ];
}

test('ninguna clase fuera de PacEnvironment lee billing.pac_mode', function () {
    $offenders = collect(File::allFiles(app_path()))
        ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'php')
        ->reject(fn (SplFileInfo $file): bool => str_ends_with(str_replace('\\', '/', $file->getPathname()), 'app/Support/Billing/PacEnvironment.php'))
        ->filter(fn (SplFileInfo $file): bool => preg_match('/[\'"]billing\.pac_mode[\'"]|[\'"]pac_mode[\'"]\s*\]/', (string) file_get_contents($file->getPathname())) === 1)
        ->map(fn (SplFileInfo $file): string => str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()))
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

test('paro de emergencia: con el techo en test una empresa Live no envía nada y no usa su llave Test', function () {
    $company = phase631LiveCompany();
    phase631MakeLive($company->id);
    config(['billing.pac_mode' => 'test']);

    foreach (phase631ProviderCalls(app(PacProviderResolver::class), $company) as $name => $call) {
        try {
            $call();
            test()->fail("{$name} no debió ejecutarse");
        } catch (PacConfigurationException $e) {
            expect($e->pacCode)->toBe('pac_live_not_enabled')
                ->and($e->getMessage())->not->toContain('PHASE_631');
        }
    }

    expect(PacCredentialGuard::forCompany($company->id)->violation())->toBe('pac_live_not_enabled');
    Http::assertNothingSent();
});

test('Live se niega fuera de production aunque el techo sea live', function (string $appEnv) {
    $company = phase631LiveCompany();
    phase631MakeLive($company->id);
    app()['env'] = $appEnv;

    foreach (phase631ProviderCalls(app(PacProviderResolver::class), $company) as $name => $call) {
        try {
            $call();
            test()->fail("{$name} no debió ejecutarse");
        } catch (PacConfigurationException $e) {
            expect($e->pacCode)->toBe('pac_live_app_env_not_allowed');
        }
    }

    Http::assertNothingSent();
})->with(['local', 'testing', 'staging']);

test('una empresa Live sin llave Live no usa la Test', function () {
    $company = phase631LiveCompany();
    phase631MakeLive($company->id);
    phase631OrganizationOf($company)->setLiveSecretKey(null)->save();

    expect(PacCredentialGuard::forCompany($company->id)->violation())->toBe('pac_live_key_missing');

    try {
        app(PacProviderResolver::class)->forCompany($company->id)->retrieveInvoice('inv_1');
        test()->fail('no debió ejecutarse');
    } catch (PacConfigurationException $e) {
        expect($e->pacCode)->toBe('pac_live_key_missing');
    }

    Http::assertNothingSent();
});

test('con techo live y production la empresa Live usa su llave Live y la Test sigue en Test', function () {
    $live = phase631LiveCompany();
    $test = Company::factory()->withFacturapiOrganization('sk_test_PHASE_631_OTHER_KEY_0001')->create();
    phase631MakeLive($live->id);
    Http::fake(['example-pac.test/v2/organizations/me' => Http::response(['id' => 'org_x', 'legal' => []])]);

    app(PacProviderResolver::class)->forCompany($live->id)->currentOrganization();
    app(CurrentTenant::class)->set($test->id);
    app(PacProviderResolver::class)->forCompany($test->id)->currentOrganization();

    $tokens = collect(Http::recorded())->map(fn (array $pair): string => $pair[0]->header('Authorization')[0])->all();

    expect($tokens)->toBe(['Bearer '.PHASE_631_LIVE_KEY, 'Bearer sk_test_PHASE_631_OTHER_KEY_0001']);
});

test('una llave Test en una empresa Live es pac_key_mode_mismatch', function () {
    $company = phase631LiveCompany();
    phase631MakeLive($company->id, 'sk_test_NOT_A_LIVE_KEY_000000000');

    expect(PacCredentialGuard::forCompany($company->id)->violation())->toBe('pac_key_mode_mismatch');
});

test('billing:facturapi-live-key no acepta la llave sin interacción', function () {
    $company = phase631LiveCompany();

    $this->artisan('billing:facturapi-live-key', ['--company' => $company->id, '--apply' => true, '--no-interaction' => true])
        ->expectsOutputToContain('sólo se captura de forma interactiva')
        ->assertExitCode(1);

    expect(phase631OrganizationOf($company)->live_secret_key)->toBeNull();
    Http::assertNothingSent();
});

test('billing:facturapi-live-key no recibe la llave por argumento', function () {
    $command = app(Kernel::class)->all()['billing:facturapi-live-key'];
    $definition = $command->getDefinition();

    expect(array_keys($definition->getArguments()))->toBe([])
        ->and(array_keys($definition->getOptions()))->not->toContain('key')
        ->and(array_keys($definition->getOptions()))->not->toContain('from');
});

test('billing:facturapi-live-key rechaza una llave sin prefijo sk_live_', function () {
    $company = phase631LiveCompany();
    phase631MakeLive($company->id);
    phase631OrganizationOf($company)->setLiveSecretKey(null)->save();

    $this->artisan('billing:facturapi-live-key', ['--company' => $company->id, '--apply' => true])
        ->expectsQuestion(PHASE_631_PROMPT, 'sk_test_PHASE_631_TEST_KEY_0001')
        ->expectsOutputToContain('no tiene el prefijo sk_live_')
        ->doesntExpectOutputToContain('PHASE_631_TEST_KEY')
        ->assertExitCode(1);

    expect(phase631OrganizationOf($company)->live_secret_key)->toBeNull();
    Http::assertNothingSent();
});

test('billing:facturapi-live-key no contacta al PAC con el techo en test', function () {
    $company = phase631LiveCompany();

    $this->artisan('billing:facturapi-live-key', ['--company' => $company->id, '--apply' => true])
        ->expectsQuestion(PHASE_631_PROMPT, PHASE_631_LIVE_KEY)
        ->expectsOutputToContain('pac_live_not_enabled')
        ->assertExitCode(1);

    expect(phase631OrganizationOf($company)->live_secret_key)->toBeNull();
    Http::assertNothingSent();
});

test('billing:facturapi-live-key comprueba la organización, guarda cifrado con --apply y audita sólo la pista', function () {
    $company = phase631LiveCompany();
    $organization = phase631OrganizationOf($company);
    config(['billing.pac_mode' => 'live']);
    app()['env'] = 'production';
    Http::fake(['example-pac.test/v2/organizations/me' => Http::response(['id' => $organization->organization_id, 'legal' => []])]);

    $this->artisan('billing:facturapi-live-key', ['--company' => $company->id])
        ->expectsQuestion(PHASE_631_PROMPT, PHASE_631_LIVE_KEY)
        ->expectsOutputToContain('Dry-run')
        ->doesntExpectOutputToContain(PHASE_631_LIVE_KEY)
        ->assertExitCode(0);

    expect(phase631OrganizationOf($company)->live_secret_key)->toBeNull();

    $this->artisan('billing:facturapi-live-key', ['--company' => $company->id, '--apply' => true])
        ->expectsQuestion(PHASE_631_PROMPT, PHASE_631_LIVE_KEY)
        ->expectsOutputToContain('Llave Live guardada: sk_live_…0001')
        ->doesntExpectOutputToContain(PHASE_631_LIVE_KEY)
        ->assertExitCode(0);

    $stored = phase631OrganizationOf($company);
    $raw = DB::table('facturapi_organizations')->where('id', $stored->id)->value('live_secret_key');
    $audit = FacturapiOrganizationAudit::withoutGlobalScopes()->where('company_id', $company->id)->latest('id')->firstOrFail();

    expect($stored->live_secret_key)->toBe(PHASE_631_LIVE_KEY)
        ->and($raw)->not->toContain(PHASE_631_LIVE_KEY)
        ->and($stored->pac_environment)->toBe('test')
        ->and($audit->action->value)->toBe('live_key_stored')
        ->and(json_encode($audit->metadata))->not->toContain(PHASE_631_LIVE_KEY);

    Http::assertSent(fn ($request): bool => $request->header('Authorization')[0] === 'Bearer '.PHASE_631_LIVE_KEY);
});

test('billing:facturapi-live-key no guarda nada si Facturapi rechaza la llave o es de otra organización', function (array $response, int $status) {
    $company = phase631LiveCompany();
    config(['billing.pac_mode' => 'live']);
    app()['env'] = 'production';
    Http::fake(['example-pac.test/v2/organizations/me' => Http::response($response, $status)]);

    $this->artisan('billing:facturapi-live-key', ['--company' => $company->id, '--apply' => true])
        ->expectsQuestion(PHASE_631_PROMPT, PHASE_631_LIVE_KEY)
        ->expectsOutputToContain('No se guardó nada')
        ->doesntExpectOutputToContain(PHASE_631_LIVE_KEY)
        ->assertExitCode(1);

    expect(phase631OrganizationOf($company)->live_secret_key)->toBeNull()
        ->and(FacturapiOrganizationAudit::withoutGlobalScopes()->where('company_id', $company->id)->count())->toBe(0);
})->with([
    'llave rechazada' => [['message' => 'Invalid API key'], 401],
    'otra organización' => [['id' => 'org_otra_cuenta', 'legal' => []], 200],
]);
