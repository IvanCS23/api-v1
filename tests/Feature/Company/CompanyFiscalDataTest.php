<?php

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Sale;
use App\Models\User;
use App\Services\Billing\InvoicePacReadinessService;
use App\Services\Billing\SaleBillingReadinessService;
use App\Services\Sat\SatFiscalValidator;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fase 6.27b (5): datos fiscales del emisor, sincronizados primero con
 * Facturapi, con la organización siempre de la empresa autenticada.
 */
const PHASE_627B_FISCAL_USER_KEY = 'sk_user_FISCAL_DATA_USER_KEY_4d4d';

beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.user_key' => PHASE_627B_FISCAL_USER_KEY,
        'billing.pac_mode' => 'test',
    ]);
    Http::preventStrayRequests();
});

function phase627bFiscalCompany(array $attributes = [], array $organization = []): Company
{
    $company = Company::factory()->withFacturapiOrganization('sk_test_FISCAL_COMPANY_KEY_0000')->create($attributes);
    DB::table('facturapi_organizations')->where('company_id', $company->id)->update(array_merge([
        'legal_tax_id' => 'XIA190128J61',
        'legal_name' => 'XENON INDUSTRIAL ARTICLES',
        'legal_tax_system' => '601',
        'legal_zip' => '06010',
    ], $organization));

    return $company;
}

function phase627bFiscalUser(Company $company, UserRole $role = UserRole::Admin): User
{
    return User::factory()->create(['company_id' => $company->id, 'role' => $role]);
}

function phase627bOrgId(Company $company): string
{
    return (string) DB::table('facturapi_organizations')->where('company_id', $company->id)->value('organization_id');
}

function phase627bFiscalPayload(array $overrides = []): array
{
    return array_merge([
        'legal_name' => 'XENON INDUSTRIAL ARTICLES',
        'trade_name' => 'Xenon',
        'rfc' => 'xia190128j61',
        'fiscal_regime' => '601',
        'postal_code' => '06010',
        'street' => 'Reforma',
        'external_number' => '100',
        'city' => 'Ciudad de México',
    ], $overrides);
}

function phase627bLegalResponse(Company $company, array $legal = []): array
{
    return [
        'id' => phase627bOrgId($company),
        'legal' => array_merge(['legal_name' => 'XENON INDUSTRIAL ARTICLES', 'tax_id' => 'XIA190128J61', 'tax_system' => '601', 'address' => ['zip' => '06010']], $legal),
        'certificate' => ['has_certificate' => false],
    ];
}

test('issuerIssues exige RFC no genérico, régimen aplicable, C.P. y razón social sin régimen societario', function (array $issuer, array $codes) {
    expect(array_column(app(SatFiscalValidator::class)->issuerIssues($issuer), 'code'))->toBe($codes);
})->with([
    'válido' => [['rfc' => 'XIA190128J61', 'legal_name' => 'XENON INDUSTRIAL ARTICLES', 'fiscal_regime' => '601', 'postal_code' => '06010'], []],
    'todo vacío' => [[], ['COMPANY_RFC_MISSING', 'COMPANY_LEGAL_NAME_MISSING', 'COMPANY_FISCAL_REGIME_MISSING', 'COMPANY_POSTAL_CODE_MISSING']],
    'RFC genérico' => [['rfc' => 'XAXX010101000', 'legal_name' => 'X', 'fiscal_regime' => '601', 'postal_code' => '06010'], ['COMPANY_RFC_GENERIC']],
    'RFC inválido' => [['rfc' => 'ABC991332XX1', 'legal_name' => 'X', 'fiscal_regime' => '601', 'postal_code' => '06010'], ['COMPANY_RFC_INVALID']],
    'régimen de persona física en una moral' => [['rfc' => 'XIA190128J61', 'legal_name' => 'X', 'fiscal_regime' => '605', 'postal_code' => '06010'], ['COMPANY_FISCAL_REGIME_INVALID']],
    'C.P. de 4 dígitos' => [['rfc' => 'XIA190128J61', 'legal_name' => 'X', 'fiscal_regime' => '601', 'postal_code' => '0601'], ['COMPANY_POSTAL_CODE_INVALID']],
    'S.A. de C.V.' => [['rfc' => 'XIA190128J61', 'legal_name' => 'XENON S.A. DE C.V.', 'fiscal_regime' => '601', 'postal_code' => '06010'], ['COMPANY_LEGAL_NAME_INVALID']],
    'S.A' => [['rfc' => 'XIA190128J61', 'legal_name' => 'TECNOLOGIAS S.A', 'fiscal_regime' => '601', 'postal_code' => '06010'], ['COMPANY_LEGAL_NAME_INVALID']],
    'S. de R.L.' => [['rfc' => 'XIA190128J61', 'legal_name' => 'Comercial, S. de R.L. de C.V.', 'fiscal_regime' => '601', 'postal_code' => '06010'], ['COMPANY_LEGAL_NAME_INVALID']],
    'palabra que termina en sa' => [['rfc' => 'XIA190128J61', 'legal_name' => 'GRUPO MASA', 'fiscal_regime' => '601', 'postal_code' => '06010'], []],
]);

test('Admin y Accountant consultan los datos fiscales sin la llave ni el id de la organización', function (UserRole $role, bool $canEdit) {
    $company = phase627bFiscalCompany(['rfc' => 'XAXX010101000', 'postal_code' => null]);

    $response = $this->actingAs(phase627bFiscalUser($company, $role), 'api')->getJson('/api/company/fiscal')->assertOk();

    expect($response->json('can_edit'))->toBe($canEdit)
        ->and(array_column($response->json('issues'), 'code'))->toBe(['COMPANY_RFC_GENERIC', 'COMPANY_POSTAL_CODE_MISSING'])
        ->and($response->json('organization.legal_tax_id'))->toBe('XIA190128J61')
        ->and($response->json('organization.rfc_matches'))->toBeFalse()
        ->and($response->getContent())->not->toContain('FISCAL_COMPANY_KEY')
        ->and($response->getContent())->not->toContain('sk_test_')
        ->and($response->getContent())->not->toContain(phase627bOrgId($company));
    Http::assertNothingSent();
})->with([
    'Admin' => [UserRole::Admin, true],
    'Accountant' => [UserRole::Accountant, false],
]);

test('Sales y Employee no consultan; sólo Admin modifica', function () {
    $company = phase627bFiscalCompany();

    $this->actingAs(phase627bFiscalUser($company, UserRole::Sales), 'api')->getJson('/api/company/fiscal')->assertForbidden();
    $this->actingAs(phase627bFiscalUser($company, UserRole::Employee), 'api')->getJson('/api/company/fiscal')->assertForbidden();
    $this->actingAs(phase627bFiscalUser($company, UserRole::Accountant), 'api')->putJson('/api/company/fiscal', phase627bFiscalPayload())->assertForbidden();

    Http::assertNothingSent();
});

test('datos inválidos, RFC distinto del CSD o sin organización se rechazan sin contactar a Facturapi', function () {
    $company = phase627bFiscalCompany(['legal_name' => 'Antes']);
    $admin = phase627bFiscalUser($company);
    $this->actingAs($admin, 'api');

    $this->putJson('/api/company/fiscal', phase627bFiscalPayload(['rfc' => 'XAXX010101000', 'postal_code' => '1']))
        ->assertStatus(422)
        ->assertJsonPath('code', 'COMPANY_FISCAL_DATA_INVALID')
        ->assertJsonPath('issues.0.field', 'rfc')
        ->assertJsonPath('issues.1.field', 'postal_code');

    $this->putJson('/api/company/fiscal', phase627bFiscalPayload(['rfc' => 'ABC010101AB1']))
        ->assertStatus(422)
        ->assertJsonPath('code', 'COMPANY_RFC_CSD_MISMATCH')
        ->assertJsonPath('issues.0.field', 'rfc');

    $unlinked = Company::factory()->create();
    $this->actingAs(phase627bFiscalUser($unlinked), 'api')
        ->putJson('/api/company/fiscal', phase627bFiscalPayload())
        ->assertStatus(409)
        ->assertJsonPath('code', 'COMPANY_PAC_ORGANIZATION_MISSING');

    expect($company->fresh()->legal_name)->toBe('Antes')
        ->and(DB::table('facturapi_organization_audits')->count())->toBe(0);
    Http::assertNothingSent();
});

test('guarda sólo después de que Facturapi acepta, con todos los campos y la auditoría del usuario', function () {
    $company = phase627bFiscalCompany(['rfc' => 'XAXX010101000', 'postal_code' => null, 'legal_name' => 'Mi Empresa']);
    $admin = phase627bFiscalUser($company);
    Http::fake(['example-pac.test/v2/organizations/*/legal' => Http::response(phase627bLegalResponse($company, ['legal_name' => 'XENON INDUSTRIAL ARTICLES']))]);

    $response = $this->actingAs($admin, 'api')->putJson('/api/company/fiscal', phase627bFiscalPayload())->assertOk();

    $company->refresh();
    $audit = DB::table('facturapi_organization_audits')->first();
    $changes = json_decode($audit->metadata, true)['changes'];

    expect($company->rfc)->toBe('XIA190128J61')
        ->and($company->postal_code)->toBe('06010')
        ->and($company->legal_name)->toBe('XENON INDUSTRIAL ARTICLES')
        ->and($response->json('issues'))->toBe([])
        ->and($response->json('organization.rfc_matches'))->toBeTrue()
        ->and($audit->action)->toBe('legal_updated')
        ->and($audit->user_id)->toBe($admin->id)
        ->and($audit->source)->toBe('api')
        ->and($changes['rfc'])->toBe(['from' => 'XAXX010101000', 'to' => 'XIA190128J61'])
        ->and($changes['postal_code'])->toBe(['from' => null, 'to' => '06010']);

    Http::assertSent(fn (Request $r): bool => $r->method() === 'PUT'
        && $r->url() === 'https://example-pac.test/v2/organizations/'.phase627bOrgId($company).'/legal'
        && $r->hasHeader('Authorization', 'Bearer '.PHASE_627B_FISCAL_USER_KEY)
        && $r->data() === [
            'name' => 'Xenon',
            'legal_name' => 'XENON INDUSTRIAL ARTICLES',
            'tax_system' => '601',
            'address' => ['zip' => '06010', 'street' => 'Reforma', 'exterior' => '100', 'city' => 'Ciudad de México'],
        ]);
    Http::assertSentCount(1);
});

test('si Facturapi rechaza o falla no se guarda nada y queda registrado sin secretos', function (int $status, string $code, int $httpStatus) {
    Log::spy();
    $company = phase627bFiscalCompany(['legal_name' => 'Antes']);
    Http::fake(['example-pac.test/v2/*' => Http::response(['code' => 'invalid_zip', 'message' => 'El código postal no existe'], $status)]);

    $this->actingAs(phase627bFiscalUser($company), 'api')
        ->putJson('/api/company/fiscal', phase627bFiscalPayload())
        ->assertStatus($httpStatus)
        ->assertJsonPath('code', $code);

    expect($company->fresh()->legal_name)->toBe('Antes')
        ->and(DB::table('facturapi_organization_audits')->count())->toBe(0);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'billing.company.fiscal_update_failed'
        && $context['company_id'] === $company->id
        && $context['code'] === $code
        && ! str_contains(json_encode($context), 'sk_'));
})->with([
    'rechazo' => [400, 'COMPANY_FISCAL_DATA_REJECTED', 422],
    'caída' => [503, 'PAC_UNAVAILABLE', 503],
    'credencial' => [401, 'PAC_AUTHENTICATION_FAILED', 502],
]);

test('sin User Key responde PAC_CONFIGURATION_INVALID sin contactar a Facturapi', function () {
    config(['services.facturapi.user_key' => null]);
    $company = phase627bFiscalCompany();

    $this->actingAs(phase627bFiscalUser($company), 'api')
        ->putJson('/api/company/fiscal', phase627bFiscalPayload())
        ->assertStatus(503)
        ->assertJsonPath('code', 'PAC_CONFIGURATION_INVALID');

    Http::assertNothingSent();
});

test('el Admin de A no afecta la organización de B', function () {
    $companyA = phase627bFiscalCompany();
    $companyB = phase627bFiscalCompany(['legal_name' => 'Empresa B']);
    $adminA = phase627bFiscalUser($companyA);
    $orgB = phase627bOrgId($companyB);
    Http::fake(['example-pac.test/v2/organizations/*/legal' => Http::response(phase627bLegalResponse($companyA))]);

    $this->actingAs($adminA, 'api')
        ->putJson('/api/company/fiscal', phase627bFiscalPayload(['organization_id' => $orgB]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('organization_id');
    $this->putJson('/api/company/fiscal', phase627bFiscalPayload(['company_id' => $companyB->id]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('company_id');
    $this->putJson('/api/company/fiscal?organization_id='.$orgB, phase627bFiscalPayload())
        ->assertStatus(422)
        ->assertJsonValidationErrors('organization_id');
    Http::assertNothingSent();

    $this->putJson('/api/company/fiscal', phase627bFiscalPayload())->assertOk();

    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/organizations/'.phase627bOrgId($companyA).'/legal'));
    Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), $orgB));
    expect($companyB->fresh()->legal_name)->toBe('Empresa B')
        ->and(DB::table('facturapi_organization_audits')->where('company_id', $companyB->id)->count())->toBe(0);
});

test('el guardado tiene límite de intentos', function () {
    $company = phase627bFiscalCompany();
    $this->actingAs(phase627bFiscalUser($company), 'api');

    for ($i = 0; $i < 10; $i++) {
        $this->putJson('/api/company/fiscal', ['rfc' => 'XAXX010101000'])->assertStatus(422);
    }

    $this->putJson('/api/company/fiscal', ['rfc' => 'XAXX010101000'])->assertStatus(429);
});

test('el readiness de emisión exige un emisor válido, el RFC del CSD y el C.P. del cliente genérico', function () {
    $company = phase627bFiscalCompany(['rfc' => 'XAXX010101000', 'postal_code' => '06010']);
    app(CurrentTenant::class)->set($company->id);
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'client_rfc' => 'XAXX010101000', 'client_codigo_postal' => '01000']);

    $codes = collect(app(InvoicePacReadinessService::class)->evaluate($invoice)['errors'])->pluck('code');

    expect($codes)->toContain('COMPANY_FISCAL_DATA_INCOMPLETE')
        ->and($codes)->toContain('COMPANY_RFC_CSD_MISMATCH')
        ->and($codes)->toContain('CLIENT_GENERIC_POSTAL_CODE_MISMATCH')
        ->and($codes)->not->toContain('COMPANY_CSD_MISSING');

    config(['billing.pac_mode' => 'live']);
    $codes = collect(app(InvoicePacReadinessService::class)->evaluate($invoice)['errors'])->pluck('code');
    expect($codes)->toContain('COMPANY_CSD_MISSING');
});

test('con el emisor corregido el readiness de emisión no reporta al emisor', function () {
    $company = phase627bFiscalCompany(['rfc' => 'XIA190128J61', 'postal_code' => '06010', 'legal_name' => 'XENON INDUSTRIAL ARTICLES']);
    app(CurrentTenant::class)->set($company->id);
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'client_rfc' => 'XAXX010101000', 'client_codigo_postal' => '06010']);

    $codes = collect(app(InvoicePacReadinessService::class)->evaluate($invoice)['errors'])->pluck('code');

    expect($codes->filter(fn (string $code): bool => str_starts_with($code, 'COMPANY_') || $code === 'CLIENT_GENERIC_POSTAL_CODE_MISMATCH')->all())->toBe([]);
});

test('el readiness de la venta exige el C.P. de la empresa para un cliente genérico', function () {
    $company = Company::factory()->create(['postal_code' => '06010']);
    app(CurrentTenant::class)->set($company->id);
    $client = Client::factory()->create(['company_id' => $company->id, 'rfc' => 'XAXX010101000', 'regimen_fiscal' => '616', 'uso_cfdi' => 'S01', 'codigo_postal' => '01000']);
    $sale = Sale::factory()->create(['company_id' => $company->id, 'client_id' => $client->id]);

    $codes = collect(app(SaleBillingReadinessService::class)->evaluate($sale)['errors'])->pluck('code');
    expect($codes)->toContain('CLIENT_GENERIC_POSTAL_CODE_MISMATCH');

    $client->update(['codigo_postal' => '06010']);
    $codes = collect(app(SaleBillingReadinessService::class)->evaluate($sale->fresh())['errors'])->pluck('code');
    expect($codes)->not->toContain('CLIENT_GENERIC_POSTAL_CODE_MISMATCH');
});
