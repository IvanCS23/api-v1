<?php

use App\Enums\InvoiceStatus;
use App\Jobs\Billing\DownloadInvoiceArtifactsJob;
use App\Models\Company;
use App\Models\FacturapiOrganization;
use App\Models\FacturapiOrganizationAudit;
use App\Models\Invoice;
use App\Services\Billing\PacEnvironmentSwitchGuard;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;

/**
 * Fase 6.31 (9): billing:pac-environment, el único camino para cambiar el
 * ambiente de una empresa.
 */
const PHASE_631_SW_LIVE_KEY = 'sk_live_PHASE_631_SWITCH_KEY_001';
const PHASE_631_SW_RFC = 'XIA190128J61';

beforeEach(function () {
    config(['services.facturapi.base_url' => 'https://example-pac.test/v2', 'billing.pac_mode' => 'live', 'queue.default' => 'sync']);
    app()['env'] = 'production';
    Http::preventStrayRequests();
});

function phase631SwitchCompany(): Company
{
    $company = Company::factory()->withFacturapiOrganization('sk_test_PHASE_631_SW_TEST_KEY_1')->create([
        'rfc' => PHASE_631_SW_RFC, 'fiscal_regime' => '601', 'postal_code' => '06010', 'email' => 'contacto@example.com',
    ]);
    FacturapiOrganization::withoutGlobalScopes()->where('company_id', $company->id)->firstOrFail()
        ->setLiveSecretKey(PHASE_631_SW_LIVE_KEY)->save();
    app(CurrentTenant::class)->clear();

    return $company;
}

function phase631SwitchOrganization(Company $company): FacturapiOrganization
{
    return FacturapiOrganization::withoutGlobalScopes()->where('company_id', $company->id)->firstOrFail();
}

function phase631RemoteOrganization(Company $company, array $overrides = []): void
{
    Http::fake(['example-pac.test/v2/organizations/me' => Http::response(array_replace_recursive([
        'id' => phase631SwitchOrganization($company)->organization_id,
        'is_production_ready' => true,
        'pending_steps' => [],
        'legal' => ['legal_name' => 'XENON', 'tax_id' => PHASE_631_SW_RFC, 'tax_system' => '601', 'address' => ['zip' => '06010'], 'support_email' => 'contacto@example.com'],
        'certificate' => ['has_certificate' => true, 'serial_number' => '30001000000500003416', 'expires_at' => now()->addYear()->toIso8601String()],
    ], $overrides))]);
}

function phase631Switch(Company $company, string $target, bool $apply = true): PendingCommand
{
    return test()->artisan('billing:pac-environment', array_filter(['--company' => $company->id, '--set' => $target, '--apply' => $apply]));
}

test('cambia a Live con datos remotos frescos, confirmación del RFC y auditoría', function () {
    $company = phase631SwitchCompany();
    phase631RemoteOrganization($company);

    phase631Switch($company, 'live')
        ->expectsQuestion('Escribe el RFC de la empresa ('.$company->trade_name.') para confirmar el cambio a live', strtolower(PHASE_631_SW_RFC))
        ->expectsOutputToContain('Ambiente cambiado: test → live')
        ->assertExitCode(0);

    $organization = phase631SwitchOrganization($company);
    $audit = FacturapiOrganizationAudit::withoutGlobalScopes()->where('company_id', $company->id)->latest('id')->firstOrFail();

    expect($organization->pac_environment)->toBe('live')
        ->and($organization->is_production_ready)->toBeTrue()
        ->and($organization->legal_support_email)->toBe('contacto@example.com')
        ->and($audit->action->value)->toBe('environment_changed')
        ->and($audit->metadata)->toBe(['from' => 'test', 'to' => 'live']);
    Http::assertSent(fn ($request): bool => $request->header('Authorization')[0] === 'Bearer '.PHASE_631_SW_LIVE_KEY);
});

test('sin --apply sólo revisa y no pide el RFC', function () {
    $company = phase631SwitchCompany();
    phase631RemoteOrganization($company);

    phase631Switch($company, 'live', apply: false)->expectsOutputToContain('Revisión: no se cambió nada')->assertExitCode(0);

    expect(phase631SwitchOrganization($company)->pac_environment)->toBe('test');
});

test('un RFC distinto no cambia nada', function () {
    $company = phase631SwitchCompany();
    phase631RemoteOrganization($company);

    phase631Switch($company, 'live')
        ->expectsQuestion('Escribe el RFC de la empresa ('.$company->trade_name.') para confirmar el cambio a live', 'AAA010101AAA')
        ->expectsOutputToContain('El RFC no coincide')
        ->assertExitCode(1);

    expect(phase631SwitchOrganization($company)->pac_environment)->toBe('test');
});

test('no se ejecuta sin interacción', function () {
    $company = phase631SwitchCompany();

    test()->artisan('billing:pac-environment', ['--company' => $company->id, '--set' => 'live', '--apply' => true, '--no-interaction' => true])
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('cada requisito remoto de Live bloquea el cambio', function (array $overrides, string $message) {
    $company = phase631SwitchCompany();
    phase631RemoteOrganization($company, $overrides);

    phase631Switch($company, 'live')->expectsOutputToContain($message)->assertExitCode(1);

    expect(phase631SwitchOrganization($company)->pac_environment)->toBe('test');
})->with([
    'otra organización' => [['id' => 'org_otra'], 'pertenece a la organización org_otra'],
    'sin CSD' => [['certificate' => ['has_certificate' => false]], 'no reporta CSD'],
    'CSD vencido' => [['certificate' => ['expires_at' => '2026-01-01T00:00:00Z']], 'vencido'],
    'no lista para producción' => [['is_production_ready' => false], 'is_production_ready=false'],
    'pasos pendientes' => [['pending_steps' => [['type' => 'manifiesto', 'description' => 'Firma']]], 'pasos pendientes: manifiesto'],
    'RFC distinto' => [['legal' => ['tax_id' => 'AAA010101AAA']], 'RFC: empresa'],
    'support_email distinto' => [['legal' => ['support_email' => 'heredado@example.com']], 'support_email'],
]);

test('el techo, el APP_ENV y la llave Live se revisan antes de cualquier HTTP', function (Closure $break, string $message) {
    $company = phase631SwitchCompany();
    $break($company);

    phase631Switch($company, 'live')->expectsOutputToContain($message)->assertExitCode(1);

    Http::assertNothingSent();
})->with([
    'techo en test' => [fn () => config(['billing.pac_mode' => 'test']), 'debe ser live'],
    'APP_ENV local' => [fn () => app()['env'] = 'local', 'no permite Live'],
    'sin llave Live' => [fn (Company $c) => phase631SwitchOrganization($c)->setLiveSecretKey(null)->save(), 'no tiene llave Live'],
]);

test('una factura Test sin terminar bloquea el cambio y se lista con su estado', function (array $attributes, string $state) {
    $company = phase631SwitchCompany();
    phase631RemoteOrganization($company);
    Invoice::factory()->create(array_merge(['company_id' => $company->id, 'status' => InvoiceStatus::Ready], $attributes));

    phase631Switch($company, 'live')->expectsOutputToContain($state)->assertExitCode(1);

    expect(phase631SwitchOrganization($company)->pac_environment)->toBe('test');
})->with([
    'sin ambiente' => [['pac_environment' => null, 'pac_draft_external_id' => 'inv_x'], 'sin_ambiente'],
    'emisión en curso' => [['status' => InvoiceStatus::Issued, 'pac_issue_status' => 'pending', 'pac_draft_external_id' => 'inv_x'], 'emision_en_curso'],
    'revisión pendiente' => [['status' => InvoiceStatus::Issued, 'pac_external_id' => 'inv_x', 'cfdi_uuid' => 'AAAAAAAA-1111-4222-8333-444444444444', 'pac_reconciliation_required' => true], 'revision_pendiente'],
    'reserva sin borrador' => [['pac_draft_idempotency_key' => 'erp-draft:1:1:v1'], 'reserva_sin_borrador'],
    'borrador sin timbrar' => [['pac_draft_external_id' => 'inv_x'], 'borrador_sin_timbrar'],
    'emitida sin CFDI' => [['status' => InvoiceStatus::Issued, 'pac_issue_status' => 'failed'], 'emitida_sin_cfdi'],
    'cancelación en curso' => [['status' => InvoiceStatus::Issued, 'pac_external_id' => 'inv_x', 'cfdi_uuid' => 'AAAAAAAA-1111-4222-8333-444444444444', 'cancellation_status' => 'pending'], 'cancelacion_en_curso'],
    'descarga en curso' => [['status' => InvoiceStatus::Issued, 'pac_external_id' => 'inv_x', 'cfdi_uuid' => 'AAAAAAAA-1111-4222-8333-444444444444', 'cfdi_artifacts_status' => 'pending'], 'descarga_en_curso'],
]);

test('las facturas Test terminadas o sin operaciones no bloquean', function () {
    $company = phase631SwitchCompany();
    phase631RemoteOrganization($company);
    Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Ready, 'pac_environment' => null]);
    Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Cancelled, 'pac_external_id' => 'inv_done', 'cfdi_uuid' => 'AAAAAAAA-1111-4222-8333-444444444444', 'pac_status' => 'canceled', 'cancellation_status' => 'accepted', 'cfdi_artifacts_status' => 'stored']);

    phase631Switch($company, 'live', apply: false)->expectsOutputToContain('Todos los requisitos se cumplen')->assertExitCode(0);
});

test('un job de la empresa en la cola bloquea el cambio', function () {
    config(['queue.default' => 'database']);
    $company = phase631SwitchCompany();
    phase631RemoteOrganization($company);
    DownloadInvoiceArtifactsJob::dispatch($company->id, 999);

    expect(app(PacEnvironmentSwitchGuard::class)->pendingJobs($company->id))->toBe(1)
        ->and(app(PacEnvironmentSwitchGuard::class)->pendingJobs($company->id + 1000))->toBe(0);

    phase631Switch($company, 'live')->expectsOutputToContain('job(s) de la empresa en la cola')->assertExitCode(1);
});

test('una cola que no se puede revisar bloquea el cambio', function () {
    config(['queue.default' => 'redis']);
    $company = phase631SwitchCompany();
    phase631RemoteOrganization($company);

    phase631Switch($company, 'live')->expectsOutputToContain('No se puede revisar la cola')->assertExitCode(1);
});

test('la vuelta a Test se niega si existe cualquier factura Live, aun borrada', function () {
    $company = phase631SwitchCompany();
    phase631SwitchOrganization($company)->forceFill(['pac_environment' => 'live'])->save();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'pac_environment' => 'live']);
    DB::table('invoices')->where('id', $invoice->id)->update(['deleted_at' => now()]);

    phase631Switch($company, 'test')->expectsOutputToContain('factura(s) Live')->assertExitCode(1);

    expect(phase631SwitchOrganization($company)->pac_environment)->toBe('live');
    Http::assertNothingSent();
});

test('la vuelta a Test sin facturas Live se permite con el RFC y sin HTTP', function () {
    $company = phase631SwitchCompany();
    phase631SwitchOrganization($company)->forceFill(['pac_environment' => 'live'])->save();

    phase631Switch($company, 'test')
        ->expectsQuestion('Escribe el RFC de la empresa ('.$company->trade_name.') para confirmar el cambio a test', PHASE_631_SW_RFC)
        ->assertExitCode(0);

    expect(phase631SwitchOrganization($company)->pac_environment)->toBe('test');
    Http::assertNothingSent();
});
