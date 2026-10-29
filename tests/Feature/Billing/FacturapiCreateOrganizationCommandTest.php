<?php

use App\Models\Company;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Fase 6.27b (2): creación de organizaciones sólo por comando del operador,
 * a prueba de un POST no idempotente.
 */
const PHASE_627B_CREATE_USER_KEY = 'sk_user_CREATE_COMMAND_USER_KEY_77aa';
const PHASE_627B_CREATE_TEST_KEY = 'sk_test_CREATED_ORGANIZATION_KEY_5b3c';
const PHASE_627B_CREATE_ORG = 'neworg0000000000000000aa';

beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.user_key' => PHASE_627B_CREATE_USER_KEY,
        'billing.pac_mode' => 'test',
    ]);
    Http::preventStrayRequests();
});

function phase627bCreatedOrganization(string $id = PHASE_627B_CREATE_ORG): array
{
    return ['id' => $id, 'legal' => ['legal_name' => null, 'tax_id' => null], 'certificate' => ['has_certificate' => false], 'pending_steps' => []];
}

/**
 * @param  array<string, mixed>  $overrides  'search', 'create', 'apikey', 'me' => respuesta o Closure.
 */
function phase627bFakeFacturapi(array $overrides = []): void
{
    $responses = array_merge([
        'search' => fn () => Http::response(['data' => []]),
        'create' => fn () => Http::response(phase627bCreatedOrganization()),
        'apikey' => fn () => Http::response(json_encode(PHASE_627B_CREATE_TEST_KEY), 200, ['Content-Type' => 'application/json']),
        'me' => fn () => Http::response(phase627bCreatedOrganization()),
    ], $overrides);

    Http::fake(function (Request $request) use ($responses) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            $request->method() === 'GET' && $path === '/v2/organizations' => $responses['search'](),
            $request->method() === 'POST' && $path === '/v2/organizations' => $responses['create'](),
            $request->method() === 'GET' && str_ends_with($path, '/apikeys/test') => $responses['apikey'](),
            $request->method() === 'GET' && $path === '/v2/organizations/me' => $responses['me'](),
            default => throw new RuntimeException("Petición inesperada: {$request->method()} {$path}"),
        };
    });
}

function phase627bCreate(Company $company, array $options = []): array
{
    $exit = Artisan::call('billing:facturapi-create-organization', array_merge(['--company' => $company->id], $options));

    return [$exit, Artisan::output()];
}

function phase627bPosts(): int
{
    return Http::recorded(fn (Request $r): bool => $r->method() === 'POST')->count();
}

test('dry-run busca por nombre y no crea nada', function () {
    phase627bFakeFacturapi();
    $company = Company::factory()->create(['trade_name' => 'Empresa Nueva']);

    [$exit, $output] = phase627bCreate($company);

    expect($exit)->toBe(0)
        ->and($output)->toContain('dry-run')
        ->and($output)->toContain('Nombre: Empresa Nueva')
        ->and(DB::table('facturapi_organizations')->count())->toBe(0)
        ->and(phase627bPosts())->toBe(0);
    Http::assertSent(fn (Request $r): bool => $r->hasHeader('Authorization', 'Bearer '.PHASE_627B_CREATE_USER_KEY));
});

test('--apply crea, guarda el id, obtiene y comprueba la llave Test y audita sin secretos', function () {
    phase627bFakeFacturapi();
    $company = Company::factory()->create(['trade_name' => 'Empresa Nueva']);

    [$exit, $output] = phase627bCreate($company, ['--apply' => true]);

    $row = DB::table('facturapi_organizations')->where('company_id', $company->id)->first();
    $audits = DB::table('facturapi_organization_audits')->orderBy('id')->get();

    expect($exit)->toBe(0)
        ->and($row->organization_id)->toBe(PHASE_627B_CREATE_ORG)
        ->and($row->test_secret_key)->not->toContain('CREATED_ORGANIZATION_KEY')
        ->and(decrypt($row->test_secret_key, false))->toBe(PHASE_627B_CREATE_TEST_KEY)
        ->and($row->test_key_hint)->toBe('sk_test_…5b3c')
        ->and($audits->pluck('action')->all())->toBe(['organization_created', 'test_key_stored'])
        ->and($audits->pluck('source')->unique()->all())->toBe(['console'])
        ->and($audits->pluck('user_id')->filter()->all())->toBe([])
        ->and($output)->toContain('sk_test_…5b3c')
        ->and($output)->not->toContain(PHASE_627B_CREATE_TEST_KEY)
        ->and($output)->not->toContain(PHASE_627B_CREATE_USER_KEY)
        ->and($audits->pluck('metadata')->implode(' '))->not->toContain('CREATED_ORGANIZATION_KEY')
        ->and(phase627bPosts())->toBe(1);

    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/organizations/me')
        && $r->hasHeader('Authorization', 'Bearer '.PHASE_627B_CREATE_TEST_KEY));
    Http::assertNotSent(fn (Request $r): bool => in_array($r->method(), ['PUT', 'DELETE'], true));
});

test('una organización con el mismo nombre en la cuenta impide crear otra', function () {
    phase627bFakeFacturapi(['search' => fn () => Http::response(['data' => [phase627bCreatedOrganization('previousorg00000000000aa')]])]);
    $company = Company::factory()->create(['trade_name' => 'Empresa Nueva']);

    [$exit, $output] = phase627bCreate($company, ['--apply' => true]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('previousorg00000000000aa')
        ->and($output)->toContain('--allow-duplicate-name')
        ->and(phase627bPosts())->toBe(0)
        ->and(DB::table('facturapi_organizations')->count())->toBe(0);

    [$exit] = phase627bCreate($company, ['--apply' => true, '--allow-duplicate-name' => true]);

    expect($exit)->toBe(0)->and(phase627bPosts())->toBe(1);
});

test('si falla después del POST queda la fila sin llave y --resume la completa sin volver a crear', function () {
    $apikeyCalls = 0;
    phase627bFakeFacturapi(['apikey' => function () use (&$apikeyCalls) {
        return ++$apikeyCalls === 1
            ? Http::response(['message' => 'Error interno'], 500)
            : Http::response(json_encode(PHASE_627B_CREATE_TEST_KEY), 200, ['Content-Type' => 'application/json']);
    }]);
    $company = Company::factory()->create();

    [$exit, $output] = phase627bCreate($company, ['--apply' => true]);
    $row = DB::table('facturapi_organizations')->where('company_id', $company->id)->first();

    expect($exit)->toBe(1)
        ->and($output)->toContain('--resume')
        ->and($row->organization_id)->toBe(PHASE_627B_CREATE_ORG)
        ->and($row->test_secret_key)->toBeNull();

    // Repetir sin --resume no crea otra organización.
    [$exit] = phase627bCreate($company, ['--apply' => true]);
    expect($exit)->toBe(1)->and(phase627bPosts())->toBe(1);

    [$exit] = phase627bCreate($company, ['--resume' => true, '--apply' => true]);

    expect($exit)->toBe(0)
        ->and(decrypt(DB::table('facturapi_organizations')->value('test_secret_key'), false))->toBe(PHASE_627B_CREATE_TEST_KEY)
        ->and(phase627bPosts())->toBe(1);
});

test('una respuesta ambigua del POST no guarda nada y pide buscar antes de repetir', function () {
    phase627bFakeFacturapi(['create' => fn () => Http::failedConnection()]);
    $company = Company::factory()->create();

    [$exit, $output] = phase627bCreate($company, ['--apply' => true]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('ambigua')
        ->and(DB::table('facturapi_organizations')->count())->toBe(0)
        ->and(phase627bPosts())->toBe(1);
});

test('una llave Test de otra organización no se guarda', function () {
    phase627bFakeFacturapi(['me' => fn () => Http::response(phase627bCreatedOrganization('someoneelse0000000000aaa'))]);
    $company = Company::factory()->create();

    [$exit, $output] = phase627bCreate($company, ['--apply' => true]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('someoneelse0000000000aaa')
        ->and(DB::table('facturapi_organizations')->value('test_secret_key'))->toBeNull();
});

test('sin User Key, o con la empresa ya vinculada, no contacta a Facturapi', function () {
    $linked = Company::factory()->withFacturapiOrganization()->create();

    [$exit, $output] = phase627bCreate($linked, ['--apply' => true]);
    expect($exit)->toBe(1)->and($output)->toContain('ya está vinculada');

    config(['services.facturapi.user_key' => null]);
    [$exit, $output] = phase627bCreate(Company::factory()->create(), ['--apply' => true]);
    expect($exit)->toBe(1)->and($output)->toContain('pac_user_key_missing');

    Http::assertNothingSent();
});

test('ninguna ruta ni controlador de la API crea organizaciones', function () {
    $files = collect(File::allFiles(app_path('Http')))->merge([base_path('routes/api.php'), base_path('routes/web.php')]);

    foreach ($files as $file) {
        expect(file_get_contents((string) $file))->not->toContain('createOrganization');
    }
});
