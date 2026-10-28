<?php

use App\Models\Company;
use App\Models\FacturapiOrganization;
use App\Models\Invoice;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Fase 6.27 (2/7): vínculo inicial de una empresa con su organización de
 * Facturapi usando la llave de .env (GET /organizations/me).
 */
const PHASE_627_LINK_KEY = 'sk_test_PHASE_627_LINK_SECRET_9z8y';
const PHASE_627_ORG = '6ad00a7079761a6ddec6026a';
const PHASE_627_OLD_ORG = '69e1341aa407ce0e33eeda06';

beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => PHASE_627_LINK_KEY,
        'billing.pac_mode' => 'test',
    ]);

    Http::preventStrayRequests();
});

function phase627OrganizationResponse(string $id = PHASE_627_ORG): array
{
    return [
        'id' => $id,
        'created_at' => '2026-04-16T19:00:00.000Z',
        'is_production_ready' => false,
        'pending_steps' => [['type' => 'manifiesto', 'description' => 'Firma la carta manifiesto']],
        'legal' => [
            'name' => 'Xenon',
            'legal_name' => 'XENON INDUSTRIAL ARTICLES',
            'tax_id' => 'XIA190128J61',
            'tax_system' => '601',
            'address' => ['zip' => '06010'],
        ],
        'certificate' => [
            'has_certificate' => true,
            'serial_number' => '30001000000500003416',
            'expires_at' => '2027-01-28T00:00:00.000Z',
            'updated_at' => '2026-04-16T19:05:00.000Z',
        ],
    ];
}

function phase627LinkCompany(): Company
{
    return Company::factory()->create([
        'legal_name' => 'Mi Empresa',
        'trade_name' => 'Mi Empresa',
        'rfc' => 'XAXX010101000',
        'fiscal_regime' => '601',
        'postal_code' => null,
    ]);
}

function phase627InvoiceInOrganization(Company $company, ?string $organization): Invoice
{
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_'.$invoice->id,
        'pac_response' => $organization === null ? ['id' => 'inv_'.$invoice->id] : ['id' => 'inv_'.$invoice->id, 'organization' => $organization],
    ])->save();

    return $invoice;
}

function phase627LinkedOrganization(Company $company): ?FacturapiOrganization
{
    app(CurrentTenant::class)->set($company->id);
    $organization = FacturapiOrganization::query()->first();
    app(CurrentTenant::class)->clear();

    return $organization;
}

test('dry-run: verifica la organización, reparte las facturas y muestra las diferencias sin guardar nada', function () {
    Http::fake(['example-pac.test/v2/organizations/me' => Http::response(phase627OrganizationResponse())]);
    $company = phase627LinkCompany();
    $old = phase627InvoiceInOrganization($company, PHASE_627_OLD_ORG);
    $current = phase627InvoiceInOrganization($company, PHASE_627_ORG);

    $exit = Artisan::call('billing:facturapi-link', ['--company' => $company->id, '--organization' => PHASE_627_ORG]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('dry-run')
        ->and($output)->toContain('sk_test_…9z8y')
        ->and($output)->toContain(PHASE_627_ORG.' (esta)')
        ->and($output)->toContain(PHASE_627_OLD_ORG)
        ->and($output)->toContain((string) $old->id)
        ->and($output)->toContain((string) $current->id)
        ->and($output)->toContain('XIA190128J61')
        ->and($output)->toContain('DIFIERE')
        ->and($output)->toContain('serie 30001000000500003416')
        ->and($output)->not->toContain(PHASE_627_LINK_KEY)
        ->and(DB::table('facturapi_organizations')->count())->toBe(0)
        ->and($company->fresh()->rfc)->toBe('XAXX010101000');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://example-pac.test/v2/organizations/me'
        && $request->hasHeader('Authorization', 'Bearer '.PHASE_627_LINK_KEY));
    Http::assertSentCount(1);
});

test('--apply guarda el vínculo con la llave cifrada y la copia de Facturapi, sin tocar la empresa', function () {
    Http::fake(['example-pac.test/v2/organizations/me' => Http::response(phase627OrganizationResponse())]);
    $company = phase627LinkCompany();

    $exit = Artisan::call('billing:facturapi-link', ['--company' => $company->id, '--organization' => PHASE_627_ORG, '--apply' => true]);
    $output = Artisan::output();

    $organization = phase627LinkedOrganization($company);

    expect($exit)->toBe(0)
        ->and($output)->toContain('Vínculo guardado')
        ->and($output)->not->toContain(PHASE_627_LINK_KEY)
        ->and($organization->organization_id)->toBe(PHASE_627_ORG)
        ->and($organization->test_secret_key)->toBe(PHASE_627_LINK_KEY)
        ->and($organization->test_key_hint)->toBe('sk_test_…9z8y')
        ->and($organization->legal_tax_id)->toBe('XIA190128J61')
        ->and($organization->legal_zip)->toBe('06010')
        ->and($organization->has_certificate)->toBeTrue()
        ->and($organization->certificate_expires_at->toDateString())->toBe('2027-01-28')
        ->and($organization->pending_steps)->toBe([['type' => 'manifiesto', 'description' => 'Firma la carta manifiesto']])
        ->and(DB::table('facturapi_organizations')->value('test_secret_key'))->not->toContain('PHASE_627')
        ->and($company->fresh()->only(['rfc', 'postal_code']))->toBe(['rfc' => 'XAXX010101000', 'postal_code' => null]);
});

test('si la llave es de otra organización no guarda nada', function () {
    Http::fake(['example-pac.test/v2/organizations/me' => Http::response(phase627OrganizationResponse(PHASE_627_OLD_ORG))]);
    $company = phase627LinkCompany();

    $exit = Artisan::call('billing:facturapi-link', ['--company' => $company->id, '--organization' => PHASE_627_ORG, '--apply' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('pertenece a la organización '.PHASE_627_OLD_ORG)
        ->and(DB::table('facturapi_organizations')->count())->toBe(0);
});

test('una llave inválida para el modo no contacta al PAC ni se muestra', function (?string $key, string $reason) {
    config(['services.facturapi.test_key' => $key]);
    $company = phase627LinkCompany();

    $exit = Artisan::call('billing:facturapi-link', ['--company' => $company->id, '--organization' => PHASE_627_ORG, '--apply' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain($reason)
        ->and($output)->not->toContain('sk_live_PHASE_627')
        ->and(DB::table('facturapi_organizations')->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'sin llave' => [null, 'pac_key_missing'],
    'llave live' => ['sk_live_PHASE_627_LINK', 'pac_key_mode_mismatch'],
]);

test('nunca sobrescribe un vínculo existente con otra organización', function () {
    $company = Company::factory()->withFacturapiOrganization('sk_test_EXISTING_LINK_0000', PHASE_627_OLD_ORG)->create();

    $exit = Artisan::call('billing:facturapi-link', ['--company' => $company->id, '--organization' => PHASE_627_ORG, '--apply' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('ya está vinculada a la organización '.PHASE_627_OLD_ORG)
        ->and(phase627LinkedOrganization($company)->test_secret_key)->toBe('sk_test_EXISTING_LINK_0000');
    Http::assertNothingSent();
});

test('una organización ya vinculada a otra empresa no se reutiliza', function () {
    Company::factory()->withFacturapiOrganization('sk_test_OTHER_COMPANY_0000', PHASE_627_ORG)->create();
    $company = phase627LinkCompany();

    $exit = Artisan::call('billing:facturapi-link', ['--company' => $company->id, '--organization' => PHASE_627_ORG, '--apply' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('ya está vinculada a la empresa');
    Http::assertNothingSent();
});

test('repetir el vínculo con la misma organización no cambia nada', function () {
    Http::fake(['example-pac.test/v2/organizations/me' => Http::response(phase627OrganizationResponse())]);
    $company = Company::factory()->withFacturapiOrganization('sk_test_EXISTING_SAME_1111', PHASE_627_ORG)->create();

    $exit = Artisan::call('billing:facturapi-link', ['--company' => $company->id, '--organization' => PHASE_627_ORG, '--apply' => true]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('No hay cambios')
        ->and(phase627LinkedOrganization($company)->test_secret_key)->toBe('sk_test_EXISTING_SAME_1111');
});

test('un error del PAC no guarda nada y no muestra la llave', function () {
    Http::fake(['example-pac.test/v2/organizations/me' => Http::response(['message' => 'API key inválida'], 401)]);
    $company = phase627LinkCompany();

    $exit = Artisan::call('billing:facturapi-link', ['--company' => $company->id, '--organization' => PHASE_627_ORG, '--apply' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('No se pudo consultar la organización')
        ->and($output)->not->toContain(PHASE_627_LINK_KEY)
        ->and(DB::table('facturapi_organizations')->count())->toBe(0);
});

test('exige --company y --organization, y una empresa existente', function () {
    expect(Artisan::call('billing:facturapi-link', ['--company' => 1]))->toBe(1)
        ->and(Artisan::call('billing:facturapi-link', ['--company' => 999999, '--organization' => PHASE_627_ORG]))->toBe(1)
        ->and(Artisan::output())->toContain('No existe la empresa');
    Http::assertNothingSent();
});

test('una respuesta sin id de organización se rechaza y no guarda nada', function () {
    Http::fake(['example-pac.test/v2/organizations/me' => Http::response(['legal' => ['tax_id' => 'XIA190128J61']])]);
    $company = phase627LinkCompany();

    $exit = Artisan::call('billing:facturapi-link', ['--company' => $company->id, '--organization' => PHASE_627_ORG, '--apply' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('no contiene el campo id')
        ->and(DB::table('facturapi_organizations')->count())->toBe(0);
});
