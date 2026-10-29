<?php

use App\Enums\FacturapiOrganizationAuditAction;
use App\Exceptions\Billing\PacAuthenticationException;
use App\Exceptions\Billing\PacConfigurationException;
use App\Exceptions\Billing\PacUnexpectedResponseException;
use App\Exceptions\Billing\PacValidationException;
use App\Infrastructure\Billing\Facturapi\FacturapiOrganizationClient;
use App\Models\Company;
use App\Models\User;
use App\Services\Billing\FacturapiOrganizationAuditor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Fase 6.27b (1): cliente de organizaciones con la User Key, con una lista
 * cerrada de operaciones, y auditoría append-only sin secretos.
 */
const PHASE_627B_USER_KEY = 'sk_user_CLIENT_TEST_USER_KEY_9f2a';
const PHASE_627B_ORG = '6ad00a7079761a6ddec6026a';

beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.user_key' => PHASE_627B_USER_KEY,
    ]);
    Http::preventStrayRequests();
});

function phase627bOrganizationResponse(array $overrides = []): array
{
    return array_replace_recursive([
        'id' => PHASE_627B_ORG,
        'legal' => ['legal_name' => 'XENON INDUSTRIAL ARTICLES', 'tax_id' => 'XIA190128J61', 'tax_system' => '601', 'address' => ['zip' => '06010']],
        'certificate' => ['has_certificate' => false],
        'is_production_ready' => false,
        'pending_steps' => [],
    ], $overrides);
}

function phase627bClient(): FacturapiOrganizationClient
{
    return app(FacturapiOrganizationClient::class);
}

test('la lista de operaciones públicas es cerrada', function () {
    $methods = collect((new ReflectionClass(FacturapiOrganizationClient::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->map->getName()
        ->sort()
        ->values()
        ->all();

    expect($methods)->toBe([
        '__construct',
        'createOrganization',
        'organization',
        'organizationsNamed',
        'testApiKey',
        'updateLegal',
        'uploadCertificate',
    ]);
});

test('sin User Key o con otro prefijo no hace ninguna petición', function (?string $key, string $reason) {
    config(['services.facturapi.user_key' => $key]);

    $calls = [
        fn () => phase627bClient()->organization(PHASE_627B_ORG),
        fn () => phase627bClient()->organizationsNamed('Empresa'),
        fn () => phase627bClient()->createOrganization('Empresa'),
        fn () => phase627bClient()->updateLegal(PHASE_627B_ORG, ['name' => 'Empresa']),
        fn () => phase627bClient()->uploadCertificate(PHASE_627B_ORG, 'cer', 'key', 'secreto'),
        fn () => phase627bClient()->testApiKey(PHASE_627B_ORG),
    ];

    foreach ($calls as $call) {
        expect($call)->toThrow(fn (PacConfigurationException $e) => expect($e->pacCode)->toBe($reason));
    }

    Http::assertNothingSent();
})->with([
    'sin llave' => [null, 'pac_user_key_missing'],
    'vacía' => ['', 'pac_user_key_missing'],
    'llave Test' => ['sk_test_NOT_A_USER_KEY', 'pac_user_key_invalid'],
    'llave Live' => ['sk_live_NOT_A_USER_KEY', 'pac_user_key_invalid'],
]);

test('consulta, crea y edita con la User Key, en las rutas esperadas', function () {
    Http::fake([
        'example-pac.test/v2/organizations/'.PHASE_627B_ORG.'/legal' => Http::response(phase627bOrganizationResponse(['legal' => ['legal_name' => 'NUEVA']])),
        'example-pac.test/v2/organizations/'.PHASE_627B_ORG => Http::response(phase627bOrganizationResponse()),
        'example-pac.test/v2/organizations?*' => Http::response(['data' => [phase627bOrganizationResponse()]]),
        'example-pac.test/v2/organizations' => Http::response(phase627bOrganizationResponse(['id' => 'neworg123'])),
    ]);

    expect(phase627bClient()->organization(PHASE_627B_ORG)->taxId)->toBe('XIA190128J61')
        ->and(phase627bClient()->organizationsNamed('Mi Empresa'))->toHaveCount(1)
        ->and(phase627bClient()->createOrganization('Mi Empresa')->id)->toBe('neworg123')
        ->and(phase627bClient()->updateLegal(PHASE_627B_ORG, ['name' => 'Mi Empresa', 'legal_name' => 'NUEVA'])->legalName)->toBe('NUEVA');

    Http::assertSent(fn (Request $r): bool => $r->method() === 'PUT'
        && $r->url() === 'https://example-pac.test/v2/organizations/'.PHASE_627B_ORG.'/legal'
        && $r['legal_name'] === 'NUEVA');
    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && $r['name'] === 'Mi Empresa');
    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'q=Mi%20Empresa') || str_contains($r->url(), 'q=Mi+Empresa'));
    Http::assertSent(fn (Request $r): bool => $r->hasHeader('Authorization', 'Bearer '.PHASE_627B_USER_KEY));
    Http::assertSentCount(4);
});

test('nunca renueva llaves ni borra: sólo GET, POST /organizations y PUT de legal o certificate', function () {
    Http::fake([
        'example-pac.test/v2/organizations/'.PHASE_627B_ORG.'/apikeys/test' => Http::response(json_encode('sk_test_FETCHED_TEST_KEY_1234'), 200, ['Content-Type' => 'application/json']),
        'example-pac.test/v2/*' => Http::response(phase627bOrganizationResponse()),
    ]);

    phase627bClient()->organization(PHASE_627B_ORG);
    phase627bClient()->organizationsNamed('x');
    phase627bClient()->createOrganization('x');
    phase627bClient()->updateLegal(PHASE_627B_ORG, ['name' => 'x']);
    phase627bClient()->uploadCertificate(PHASE_627B_ORG, 'cer', 'key', 'secreto');
    phase627bClient()->testApiKey(PHASE_627B_ORG);

    Http::assertNotSent(fn (Request $r): bool => $r->method() === 'DELETE');
    Http::assertNotSent(fn (Request $r): bool => $r->method() !== 'GET' && str_contains($r->url(), '/apikeys'));
    Http::assertNotSent(fn (Request $r): bool => $r->method() === 'PUT' && ! preg_match('#/(legal|certificate)$#', $r->url()));
});

test('sube el CSD como multipart y borra la contraseña de un error del PAC', function () {
    Http::fake(['example-pac.test/v2/*' => Http::response(['code' => 'invalid_certificate', 'message' => 'La contraseña secreto-del-csd no corresponde a la llave'], 400)]);

    try {
        phase627bClient()->uploadCertificate(PHASE_627B_ORG, 'CER-BYTES', 'KEY-BYTES', 'secreto-del-csd');
        $this->fail('Debió lanzar PacValidationException.');
    } catch (PacValidationException $e) {
        expect($e->getMessage())->not->toContain('secreto-del-csd')
            ->and($e->getMessage())->toContain('[redacted]')
            ->and($e->pacCode)->toBe('invalid_certificate');
    }

    Http::assertSent(function (Request $r): bool {
        $parts = collect($r->data())->keyBy('name');

        return $r->method() === 'PUT'
            && $r->isMultipart()
            && $parts['cer']['contents'] === 'CER-BYTES'
            && $parts['key']['contents'] === 'KEY-BYTES'
            && $parts['password']['contents'] === 'secreto-del-csd';
    });
    Http::assertSentCount(1);
});

test('obtiene la llave Test sin renovarla y nunca muestra una respuesta inesperada', function () {
    Http::fake(['example-pac.test/v2/organizations/'.PHASE_627B_ORG.'/apikeys/test' => Http::sequence()
        ->push(json_encode('sk_test_FETCHED_TEST_KEY_1234'), 200, ['Content-Type' => 'application/json'])
        ->push(json_encode('sk_live_UNEXPECTED_LIVE_KEY'), 200, ['Content-Type' => 'application/json'])]);

    expect(phase627bClient()->testApiKey(PHASE_627B_ORG))->toBe('sk_test_FETCHED_TEST_KEY_1234');

    expect(fn () => phase627bClient()->testApiKey(PHASE_627B_ORG))
        ->toThrow(fn (PacUnexpectedResponseException $e) => expect($e->getMessage())->not->toContain('UNEXPECTED_LIVE_KEY'));

    Http::assertSent(fn (Request $r): bool => $r->method() === 'GET');
});

test('los mensajes de error nunca incluyen llaves', function () {
    Http::fake(['example-pac.test/v2/*' => Http::response(['message' => 'Llave sk_user_LEAKED_IN_MESSAGE inválida'], 401)]);

    expect(fn () => phase627bClient()->organization(PHASE_627B_ORG))
        ->toThrow(fn (PacAuthenticationException $e) => expect($e->getMessage())->not->toContain('LEAKED_IN_MESSAGE'));
});

test('un id de organización con caracteres de ruta se rechaza sin HTTP', function (string $id) {
    expect(fn () => phase627bClient()->organization($id))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
})->with(['../apikeys/test', 'org/legal', 'org?x=1', '']);

test('la auditoría guarda el usuario desde la API, consola sin usuario, y nunca llaves', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $auditor = app(FacturapiOrganizationAuditor::class);

    $api = $auditor->record($company->id, PHASE_627B_ORG, FacturapiOrganizationAuditAction::LegalUpdated, $user, ['fields' => ['legal_name']]);
    $console = $auditor->record($company->id, PHASE_627B_ORG, FacturapiOrganizationAuditAction::TestKeyReplaced, null, ['key_hint' => 'sk_test_…1234']);

    expect($api->fresh()->user_id)->toBe($user->id)
        ->and($api->source)->toBe('api')
        ->and($console->source)->toBe('console')
        ->and($console->user_id)->toBeNull()
        ->and($console->fresh()->action)->toBe(FacturapiOrganizationAuditAction::TestKeyReplaced);

    expect(fn () => $auditor->record($company->id, PHASE_627B_ORG, FacturapiOrganizationAuditAction::TestKeyReplaced, null, ['key' => 'sk_test_REAL_SECRET_VALUE']))
        ->toThrow(LogicException::class);
    expect(DB::table('facturapi_organization_audits')->count())->toBe(2);

    expect(fn () => $api->forceFill(['action' => FacturapiOrganizationAuditAction::CertificateUploaded])->save())->toThrow(LogicException::class)
        ->and(fn () => $api->delete())->toThrow(LogicException::class);
});
