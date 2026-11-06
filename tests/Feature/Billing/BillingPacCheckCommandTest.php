<?php

use App\Enums\InvoiceStatus;
use App\Infrastructure\Billing\Facturapi\FacturapiOrganizationSnapshot;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\Billing\PacCheckService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Fase 6.27 (7/7): billing:pac-check verifica la organización y la llave de
 * Facturapi de cada empresa, sin escribir nada.
 */
const PHASE_627_CHECK_ORG = 'org_check_6ad00a70';
const PHASE_627_CHECK_KEY = 'sk_test_PAC_CHECK_COMPANY_KEY_7c3d';

beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_ENV_NOT_USED_BY_PAC_CHECK',
        'billing.pac_mode' => 'test',
    ]);

    Http::preventStrayRequests();
    $this->travelTo('2026-10-28 12:00:00');
});

function phase627CheckCompany(array $attributes = [], ?string $key = PHASE_627_CHECK_KEY): Company
{
    return Company::factory()->withFacturapiOrganization($key, PHASE_627_CHECK_ORG)->create(array_merge([
        'trade_name' => 'Empresa verificada',
        'rfc' => 'XIA190128J61',
        'fiscal_regime' => '601',
        'postal_code' => '06010',
        // Fase 6.31: correo de contacto sincronizado con legal.support_email.
        'email' => 'contacto@example.com',
    ], $attributes));
}

function phase627CheckOrganization(array $overrides = []): array
{
    return array_replace_recursive([
        'id' => PHASE_627_CHECK_ORG,
        'is_production_ready' => false,
        'pending_steps' => [['type' => 'manifiesto', 'description' => 'Firma la carta manifiesto']],
        'legal' => ['legal_name' => 'XENON INDUSTRIAL ARTICLES', 'tax_id' => 'XIA190128J61', 'tax_system' => '601', 'address' => ['zip' => '06010'], 'support_email' => 'contacto@example.com'],
        'certificate' => ['has_certificate' => true, 'serial_number' => '30001000000500003416', 'expires_at' => '2027-06-01T00:00:00.000Z'],
    ], $overrides);
}

/** @return array{0: int, 1: array<string, mixed>} */
function phase627PacCheck(array $options = []): array
{
    $exit = Artisan::call('billing:pac-check', array_merge(['--format' => 'json'], $options));

    return [$exit, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)];
}

function phase627CheckStatuses(array $company): array
{
    return array_column($company['checks'], 'status', 'code');
}

test('una empresa bien configurada pasa todas las verificaciones sin escribir nada', function () {
    Http::fake(['example-pac.test/v2/organizations/me' => Http::response(phase627CheckOrganization())]);
    $company = phase627CheckCompany();
    $before = DB::table('facturapi_organizations')->get()->toArray();

    [$exit, $report] = phase627PacCheck(['--company' => [$company->id]]);
    $statuses = phase627CheckStatuses($report['companies'][0]);

    expect($exit)->toBe(0)
        ->and($report['read_only'])->toBeTrue()
        ->and($report['companies'][0]['status'])->toBe('ok')
        ->and($statuses)->toMatchArray([
            'organization' => 'ok', 'credentials' => 'ok', 'remote_identity' => 'ok',
            'fiscal_rfc' => 'ok', 'fiscal_regime' => 'ok', 'fiscal_postal_code' => 'ok',
            'certificate' => 'ok', 'pending_steps' => 'info', 'account' => 'info',
            'support_email' => 'ok', 'logo' => 'ok',
            'invoices_without_organization' => 'ok', 'invoices_other_organization' => 'ok', 'invoices_other_environment' => 'ok', 'invoices_archived' => 'info',
        ])
        ->and(DB::table('facturapi_organizations')->get()->toArray())->toEqual($before);

    $json = json_encode($report, JSON_UNESCAPED_UNICODE);
    expect($json)->toContain('sk_test_…7c3d')
        ->and($json)->not->toContain(PHASE_627_CHECK_KEY);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->hasHeader('Authorization', 'Bearer '.PHASE_627_CHECK_KEY));
    Http::assertSentCount(1);
});

test('sin organización o con una llave de otro modo falla sin contactar al PAC', function () {
    $unlinked = Company::factory()->create();
    $liveKey = phase627CheckCompany(['rfc' => 'XIA190128J62'], 'sk_live_PAC_CHECK_LIVE_KEY');

    [$exit, $report] = phase627PacCheck(['--company' => [$unlinked->id, $liveKey->id]]);

    expect($exit)->toBe(1)
        ->and(phase627CheckStatuses($report['companies'][0])['organization'])->toBe('fail')
        ->and(phase627CheckStatuses($report['companies'][1])['credentials'])->toBe('fail')
        ->and($report['companies'][1]['checks'][1]['detail'])->toContain('pac_key_mode_mismatch')
        ->and(json_encode($report))->not->toContain('PAC_CHECK_LIVE_KEY');
    Http::assertNothingSent();
});

test('una llave que pertenece a otra organización falla la identidad remota', function () {
    Http::fake(['example-pac.test/v2/organizations/me' => Http::response(phase627CheckOrganization(['id' => 'org_someone_else']))]);
    $company = phase627CheckCompany();

    [$exit, $report] = phase627PacCheck(['--company' => [$company->id]]);
    $identity = collect($report['companies'][0]['checks'])->firstWhere('code', 'remote_identity');

    expect($exit)->toBe(1)
        ->and($identity['status'])->toBe('fail')
        ->and($identity['detail'])->toContain('org_someone_else');
});

test('un error del PAC falla la identidad remota sin mostrar la llave', function () {
    Http::fake(['example-pac.test/v2/organizations/me' => Http::response(['message' => 'API key inválida'], 401)]);
    $company = phase627CheckCompany();

    [$exit, $report] = phase627PacCheck(['--company' => [$company->id]]);

    expect($exit)->toBe(1)
        ->and(phase627CheckStatuses($report['companies'][0])['remote_identity'])->toBe('fail')
        ->and(json_encode($report))->not->toContain(PHASE_627_CHECK_KEY);
});

test('reporta las diferencias de datos fiscales con Facturapi sin corregirlas', function () {
    Http::fake(['example-pac.test/v2/organizations/me' => Http::response(phase627CheckOrganization())]);
    $company = phase627CheckCompany(['rfc' => 'XAXX010101000', 'postal_code' => null]);

    [$exit, $report] = phase627PacCheck(['--company' => [$company->id]]);
    $checks = collect($report['companies'][0]['checks'])->keyBy('code');

    expect($exit)->toBe(1)
        ->and($checks['fiscal_rfc']['status'])->toBe('fail')
        ->and($checks['fiscal_rfc']['detail'])->toBe('RFC: empresa XAXX010101000, Facturapi XIA190128J61. Corrige la empresa a mano.')
        ->and($checks['fiscal_postal_code']['detail'])->toContain('empresa (vacío), Facturapi 06010')
        ->and($checks['fiscal_regime']['status'])->toBe('ok')
        ->and($company->fresh()->rfc)->toBe('XAXX010101000');
});

test('el CSD en modo test: vigente ok, por vencer o ausente warn, vencido fail', function (array $certificate, string $status, int $exitDefault, int $exitWarn) {
    Http::fake(['example-pac.test/v2/organizations/me' => Http::response(phase627CheckOrganization(['certificate' => $certificate]))]);
    $company = phase627CheckCompany();

    [$exit] = phase627PacCheck(['--company' => [$company->id]]);
    [$exitOnWarn, $report] = phase627PacCheck(['--company' => [$company->id], '--fail-on' => 'warn']);

    expect(phase627CheckStatuses($report['companies'][0])['certificate'])->toBe($status)
        ->and($exit)->toBe($exitDefault)
        ->and($exitOnWarn)->toBe($exitWarn);
})->with([
    'vigente' => [['expires_at' => '2027-06-01T00:00:00.000Z'], 'ok', 0, 0],
    'vence en 10 días' => [['expires_at' => '2026-11-07T12:00:00.000Z'], 'warn', 0, 1],
    'vencido' => [['expires_at' => '2026-10-01T00:00:00.000Z'], 'fail', 1, 1],
    'sin CSD' => [['has_certificate' => false], 'warn', 0, 1],
]);

test('el CSD ausente es aviso en modo test y fallo en modo live', function (string $mode, string $status) {
    $snapshot = FacturapiOrganizationSnapshot::fromResponse(phase627CheckOrganization(['certificate' => ['has_certificate' => false]]));

    $check = app(PacCheckService::class)->certificateCheck($snapshot, CarbonImmutable::now(), $mode);

    expect($check['code'])->toBe('certificate')
        ->and($check['status'])->toBe($status);
})->with([
    'test' => ['test', 'warn'],
    'live' => ['live', 'fail'],
]);

test('con User Key verifica que la organización pertenezca a la cuenta', function (?string $userKey, ?array $accountResponse, string $status, string $detail) {
    config(['services.facturapi.user_key' => $userKey]);
    Http::fake(array_filter([
        'example-pac.test/v2/organizations/me' => Http::response(phase627CheckOrganization()),
        'example-pac.test/v2/organizations/'.PHASE_627_CHECK_ORG => $accountResponse === null ? null : Http::response(...$accountResponse),
    ]));
    $company = phase627CheckCompany();

    [, $report] = phase627PacCheck(['--company' => [$company->id]]);
    $account = collect($report['companies'][0]['checks'])->firstWhere('code', 'account');

    expect($account['status'])->toBe($status)
        ->and($account['detail'])->toContain($detail)
        ->and(json_encode($report))->not->toContain('PAC_CHECK_USER_KEY')
        ->and(json_encode($report))->not->toContain('mensaje interno del PAC');

    if ($accountResponse !== null) {
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/organizations/'.PHASE_627_CHECK_ORG)
            && $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer '.$userKey));
    } else {
        Http::assertSentCount(1);
    }
})->with([
    'pertenece' => ['sk_user_PAC_CHECK_USER_KEY_1111', [phase627CheckOrganization()], 'ok', 'pertenece a la cuenta'],
    'sin acceso' => ['sk_user_PAC_CHECK_USER_KEY_1111', [['code' => 'not_found', 'message' => 'mensaje interno del PAC'], 404], 'fail', 'HTTP 404, not_found'],
    'otra organización' => ['sk_user_PAC_CHECK_USER_KEY_1111', [phase627CheckOrganization(['id' => 'org_other'])], 'fail', 'org_other'],
    'prefijo inválido' => ['sk_test_PAC_CHECK_USER_KEY_1111', null, 'fail', 'pac_user_key_invalid'],
    'sin User Key' => [null, null, 'info', 'Sin User Key'],
]);

test('cuenta las facturas sin organización, de otra organización y archivadas', function () {
    Http::fake(['example-pac.test/v2/organizations/me' => Http::response(phase627CheckOrganization())]);
    $company = phase627CheckCompany();
    $make = function (?string $organization, bool $archived = false) use ($company): Invoice {
        $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
        DB::table('invoices')->where('id', $invoice->id)->update([
            'pac_external_id' => 'inv_check_'.$invoice->id,
            'pac_organization_id' => $organization,
            'pac_organization_archived_at' => $archived ? now() : null,
        ]);

        return $invoice;
    };
    $make(PHASE_627_CHECK_ORG);
    $missing = $make(null);
    $other = $make('org_previous');
    $archived = $make('org_previous', true);

    [$exit, $report] = phase627PacCheck(['--company' => [$company->id]]);
    $checks = collect($report['companies'][0]['checks'])->keyBy('code');

    expect($exit)->toBe(1)
        ->and($checks['invoices_without_organization']['status'])->toBe('warn')
        ->and($checks['invoices_without_organization']['detail'])->toContain("#{$missing->id}")
        ->and($checks['invoices_other_organization']['status'])->toBe('fail')
        ->and($checks['invoices_other_organization']['detail'])->toContain("#{$other->id}")
        ->and($checks['invoices_archived']['detail'])->toContain("#{$archived->id}");
});

test('la salida de tabla muestra la pista de la llave y no la llave', function () {
    Http::fake(['example-pac.test/v2/organizations/me' => Http::response(phase627CheckOrganization())]);
    $company = phase627CheckCompany();

    $exit = Artisan::call('billing:pac-check', ['--company' => [$company->id]]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Empresa '.$company->id.' (Empresa verificada): OK')
        ->and($output)->toContain('sk_test_…7c3d')
        ->and($output)->not->toContain(PHASE_627_CHECK_KEY);
});

test('rechaza opciones inválidas', function () {
    expect(Artisan::call('billing:pac-check', ['--format' => 'xml']))->toBe(1)
        ->and(Artisan::call('billing:pac-check', ['--fail-on' => 'invalid']))->toBe(1);
    Http::assertNothingSent();
});

test('en Live pac-check exige lista para producción y el correo de respuesta (Fase 6.31)', function (array $overrides, array $companyAttributes, array $expected) {
    $company = phase627CheckCompany($companyAttributes);
    phase631MakeLive($company->id, 'sk_live_PAC_CHECK_LIVE_KEY_000000');
    $remote = phase627CheckOrganization(array_replace_recursive(['is_production_ready' => true], $overrides));
    // array_replace_recursive conserva el paso pendiente del fixture base con [].
    $remote['pending_steps'] = $overrides['pending_steps'] ?? [];
    Http::fake(['example-pac.test/v2/organizations/me' => Http::response($remote)]);

    [$exit, $report] = phase627PacCheck(['--company' => [$company->id]]);
    $statuses = phase627CheckStatuses($report['companies'][0]);

    expect($statuses)->toMatchArray($expected)
        ->and($exit)->toBe(in_array('fail', $expected, true) ? 1 : 0)
        ->and(json_encode($report))->not->toContain('sk_live_PAC_CHECK_LIVE_KEY_000000');
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer sk_live_PAC_CHECK_LIVE_KEY_000000'));
})->with([
    'lista' => [[], [], ['pending_steps' => 'ok', 'support_email' => 'ok', 'credentials' => 'ok']],
    'pasos pendientes' => [['pending_steps' => [['type' => 'manifiesto', 'description' => 'Firma']]], [], ['pending_steps' => 'fail']],
    'no lista' => [['is_production_ready' => false], [], ['pending_steps' => 'fail']],
    'support_email distinto' => [['legal' => ['support_email' => 'heredado@example.com']], [], ['support_email' => 'fail']],
    'sin correo de contacto' => [[], ['email' => null], ['support_email' => 'fail']],
]);

test('pac-check avisa de un logotipo que no se subió desde el sistema (Fase 6.31)', function () {
    $company = phase627CheckCompany();
    Http::fake(['example-pac.test/v2/organizations/me' => Http::response(phase627CheckOrganization(['customization' => ['has_logo' => true]]))]);

    [, $report] = phase627PacCheck(['--company' => [$company->id]]);

    expect(phase627CheckStatuses($report['companies'][0])['logo'])->toBe('warn');
});

test('pac-check reporta facturas de otro ambiente o sin ambiente (Fase 6.31)', function () {
    $company = phase627CheckCompany();
    Invoice::factory()->create(['company_id' => $company->id, 'pac_environment' => 'live', 'pac_external_id' => 'inv_live', 'pac_organization_id' => PHASE_627_CHECK_ORG]);
    Http::fake(['example-pac.test/v2/organizations/me' => Http::response(phase627CheckOrganization())]);

    [$exit, $report] = phase627PacCheck(['--company' => [$company->id]]);

    expect(phase627CheckStatuses($report['companies'][0])['invoices_other_environment'])->toBe('fail')
        ->and($exit)->toBe(1);
});
