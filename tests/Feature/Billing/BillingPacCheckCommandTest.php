<?php

use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
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
    ], $attributes));
}

function phase627CheckOrganization(array $overrides = []): array
{
    return array_replace_recursive([
        'id' => PHASE_627_CHECK_ORG,
        'is_production_ready' => false,
        'pending_steps' => [['type' => 'manifiesto', 'description' => 'Firma la carta manifiesto']],
        'legal' => ['legal_name' => 'XENON INDUSTRIAL ARTICLES', 'tax_id' => 'XIA190128J61', 'tax_system' => '601', 'address' => ['zip' => '06010']],
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
            'invoices_without_organization' => 'ok', 'invoices_other_organization' => 'ok', 'invoices_archived' => 'info',
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

test('el CSD: vigente ok, por vencer warn, vencido o ausente fail', function (array $certificate, string $status, int $exitDefault, int $exitWarn) {
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
    'sin CSD' => [['has_certificate' => false], 'fail', 1, 1],
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
