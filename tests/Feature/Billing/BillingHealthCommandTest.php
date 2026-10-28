<?php

use App\Enums\InvoicePacEventType;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\Billing\InvoicePacAuditService;
use App\Support\Billing\PacIdentifiers;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    facturapiOrganizationsFromConfigKey();
    config(['services.facturapi.test_key' => 'sk_test_PHASE_618_SECRET']);
    Http::preventStrayRequests();
    Http::fake();
});

const PHASE_618_UUID = '96013e83-154b-4153-8e61-c38b8966e560';

function phase618Invoice(Company $company, InvoiceStatus $status, array $pac = []): Invoice
{
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'status' => $status,
    ]);

    if ($pac !== []) {
        $invoice->forceFill($pac)->save();
    }

    return $invoice->fresh();
}

function phase618HealthyIssued(Company $company): Invoice
{
    $invoice = phase618Invoice($company, InvoiceStatus::Issued);
    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_phase618_'.$invoice->id,
        'cfdi_uuid' => PHASE_618_UUID,
        'pac_status' => 'valid',
        'pac_issue_status' => 'succeeded',
        'pac_draft_idempotency_key' => PacIdentifiers::draftIdempotencyKey($company->id, $invoice->id),
        'cfdi_artifacts_status' => 'stored',
        'pac_response' => ['secret' => 'sk_test_PHASE_618_SECRET'],
        'pac_last_error' => 'error crudo con sk_test_PHASE_618_SECRET',
        'cfdi_xml_path' => 'private/cfdi/secret-path.xml',
    ])->save();

    return $invoice->fresh();
}

/** @return array{0: int, 1: array<string, mixed>} */
function runBillingHealthJson(array $options = []): array
{
    $exit = Artisan::call('billing:health', array_merge(['--format' => 'json'], $options));

    return [$exit, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)];
}

test('reporte JSON clasifica todas las empresas, lista no-validas y respeta el contrato', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $healthy = phase618HealthyIssued($companyA);
    $legacyIssued = phase618Invoice($companyA, InvoiceStatus::Issued);
    $invalid = phase618Invoice($companyB, InvoiceStatus::Ready, [
        'pac_external_id' => 'inv_phase618_ready',
        'cfdi_uuid' => PHASE_618_UUID,
        'pac_status' => 'valid',
    ]);
    phase618Invoice($companyB, InvoiceStatus::Draft);

    [$exit, $report] = runBillingHealthJson();

    expect($exit)->toBe(1)
        ->and($report)->toHaveKeys(['generated_at', 'read_only', 'scope', 'thresholds', 'evaluated', 'worst', 'totals', 'by_code', 'by_state', 'informational', 'invoices', 'truncated'])
        ->and($report['read_only'])->toBeTrue()
        ->and($report['scope']['companies'])->toBe(2)
        ->and($report['evaluated'])->toBe(4)
        ->and($report['worst'])->toBe('invalid')
        ->and($report['totals'])->toBe(['valid' => 2, 'transient' => 0, 'recoverable' => 1, 'invalid' => 1])
        ->and($report['by_code'])->toMatchArray(['erp_issued_without_cfdi' => 1, 'erp_behind_cfdi' => 1])
        ->and($report['by_state'])->toMatchArray(['issued|valid' => 1, 'issued|none' => 1, 'ready|valid' => 1, 'draft|none' => 1])
        ->and(collect($report['invoices'])->pluck('id')->all())->toEqualCanonicalizing([$legacyIssued->id, $invalid->id])
        ->and(collect($report['invoices'])->pluck('id')->all())->not->toContain($healthy->id)
        ->and(array_keys($report['invoices'][0]))->toBe(['id', 'company_id', 'folio', 'status', 'pac_status', 'pac_issue_status', 'cancellation_status', 'health', 'findings'])
        ->and(array_keys($report['invoices'][0]['findings'][0]))->toBe(['code', 'status', 'informational', 'action', 'age_minutes'])
        ->and($report['truncated'])->toBeFalse();

    Http::assertNothingSent();
});

test('la salida nunca expone payloads, errores, rutas, llaves ni identidad fiscal', function () {
    $company = Company::factory()->create();
    $invoice = phase618HealthyIssued($company);
    $invoice->forceFill(['cfdi_artifacts_status' => null])->save();

    foreach (['json', 'table'] as $format) {
        Artisan::call('billing:health', ['--format' => $format, '--status' => ['valid', 'recoverable']]);
        $output = Artisan::output();

        expect($output)->toContain('cfdi_artifacts_missing')
            ->not->toContain('sk_test_PHASE_618_SECRET')
            ->not->toContain('error crudo')
            ->not->toContain('secret-path')
            ->not->toContain('erp-invoice')
            ->not->toContain(PHASE_618_UUID)
            ->not->toContain('inv_phase618_');
    }

    Http::assertNothingSent();
});

test('--company aisla el reporte a una sola empresa', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $own = phase618Invoice($companyA, InvoiceStatus::Issued);
    phase618Invoice($companyB, InvoiceStatus::Issued);
    phase618Invoice($companyB, InvoiceStatus::Ready, ['cfdi_uuid' => PHASE_618_UUID]);

    [$exit, $report] = runBillingHealthJson(['--company' => $companyA->id]);

    expect($exit)->toBe(0)
        ->and($report['scope']['companies'])->toBe(1)
        ->and($report['evaluated'])->toBe(1)
        ->and(collect($report['invoices'])->pluck('company_id')->unique()->all())->toBe([$companyA->id])
        ->and(collect($report['invoices'])->pluck('id')->all())->toBe([$own->id]);
});

test('--invoice evalua una sola factura y rechaza IDs fuera del alcance', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $target = phase618Invoice($companyB, InvoiceStatus::Issued);
    phase618Invoice($companyB, InvoiceStatus::Issued);

    [$exit, $report] = runBillingHealthJson(['--invoice' => $target->id]);

    expect($exit)->toBe(0)
        ->and($report['evaluated'])->toBe(1)
        ->and($report['invoices'][0]['id'])->toBe($target->id);

    expect(Artisan::call('billing:health', ['--invoice' => $target->id, '--company' => $companyA->id]))->toBe(1)
        ->and(Artisan::output())->toContain('No existe ninguna Invoice');
    expect(Artisan::call('billing:health', ['--invoice' => 999999]))->toBe(1);
    expect(Artisan::call('billing:health', ['--company' => 999999]))->toBe(1)
        ->and(Artisan::output())->toContain('No existe ninguna empresa');
});

test('el diagnostico solo emite SELECT y no altera facturas ni eventos', function () {
    $company = Company::factory()->create();
    $pending = phase618HealthyIssued($company);
    $pending->forceFill(['cancellation_status' => 'pending', 'pac_reconciliation_required' => true])->save();
    app(CurrentTenant::class)->set($company->id);
    app(InvoicePacAuditService::class)->append($pending, InvoicePacEventType::CancellationRequested, ['motive' => '02']);
    app(CurrentTenant::class)->clear();
    phase618Invoice($company, InvoiceStatus::Issued, ['pac_issue_status' => 'failed']);

    $before = DB::table('invoices')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    $eventsBefore = DB::table('invoice_pac_events')->count();
    $statements = [];
    DB::listen(function ($query) use (&$statements) {
        $statements[] = strtolower(ltrim($query->sql));
    });

    Artisan::call('billing:health', ['--format' => 'json', '--status' => ['valid', 'transient', 'recoverable', 'invalid']]);

    expect($statements)->not->toBeEmpty();
    foreach ($statements as $sql) {
        expect($sql)->toStartWith('select');
    }
    expect(DB::table('invoices')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($before)
        ->and(DB::table('invoice_pac_events')->count())->toBe($eventsBefore);
    Http::assertNothingSent();
});

test('la cancelacion pendiente usa el evento CancellationRequested sobre last_pac_sync_at', function () {
    $company = Company::factory()->create();
    $invoice = phase618HealthyIssued($company);
    $invoice->forceFill([
        'cancellation_status' => 'pending',
        'pac_reconciliation_required' => true,
        'last_pac_sync_at' => now()->subDays(10),
    ])->save();
    app(CurrentTenant::class)->set($company->id);
    app(InvoicePacAuditService::class)->append($invoice, InvoicePacEventType::CancellationRequested, ['motive' => '02']);
    app(CurrentTenant::class)->clear();

    [$exit, $report] = runBillingHealthJson(['--fail-on' => 'recoverable']);

    expect($exit)->toBe(0)
        ->and($report['totals']['transient'])->toBe(1)
        ->and($report['invoices'][0]['findings'][0]['code'])->toBe('cancellation_pending_recent');

    $this->travel(97)->hours();
    [$exitLater, $later] = runBillingHealthJson(['--fail-on' => 'recoverable']);

    expect($exitLater)->toBe(1)
        ->and($later['invoices'][0]['findings'][0]['code'])->toBe('cancellation_pending_stale')
        ->and(DB::table('invoice_pac_events')->count())->toBe(1);
});

test('--fail-on controla el codigo de salida', function () {
    $company = Company::factory()->create();
    phase618Invoice($company, InvoiceStatus::Issued, [
        'pac_issue_status' => 'pending',
        'pac_issue_started_at' => now()->subMinutes(5),
    ]);

    expect(Artisan::call('billing:health'))->toBe(0)
        ->and(Artisan::call('billing:health', ['--fail-on' => 'transient']))->toBe(1)
        ->and(Artisan::call('billing:health', ['--fail-on' => 'none']))->toBe(0);
});

test('cfdi_artifacts_missing es informativo y no dispara --fail-on', function () {
    $company = Company::factory()->create();
    $invoice = phase618HealthyIssued($company);
    $invoice->forceFill(['cfdi_artifacts_status' => null])->save();

    [$exit, $report] = runBillingHealthJson(['--fail-on' => 'transient', '--status' => ['valid']]);

    expect($exit)->toBe(0)
        ->and($report['worst'])->toBe('valid')
        ->and($report['totals']['valid'])->toBe(1)
        ->and($report['by_code'])->toBe(['cfdi_artifacts_missing' => 1])
        ->and($report['invoices'][0]['findings'][0])->toMatchArray([
            'code' => 'cfdi_artifacts_missing',
            'status' => 'valid',
            'informational' => true,
        ]);

    $this->artisan('billing:health', ['--status' => ['valid']])
        ->expectsOutputToContain('cfdi_artifacts_missing→fetch_artifacts (info)')
        ->assertExitCode(0);
});

test('--limit trunca el listado pero no los totales', function () {
    $company = Company::factory()->create();
    phase618Invoice($company, InvoiceStatus::Issued);
    phase618Invoice($company, InvoiceStatus::Issued);
    phase618Invoice($company, InvoiceStatus::Issued);

    [, $report] = runBillingHealthJson(['--limit' => 2]);

    expect($report['totals']['recoverable'])->toBe(3)
        ->and($report['invoices'])->toHaveCount(2)
        ->and($report['truncated'])->toBeTrue();
});

test('opciones invalidas terminan con codigo INVALID sin evaluar', function (array $options) {
    expect(Artisan::call('billing:health', $options))->toBe(2);
})->with([
    'formato' => [['--format' => 'xml']],
    'fail-on' => [['--fail-on' => 'valid']],
    'status' => [['--status' => ['broken']]],
    'limit' => [['--limit' => '-1']],
    'company' => [['--company' => 'abc']],
]);

test('restaura el tenant previo y no lo deja contaminado', function () {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    phase618Invoice($company, InvoiceStatus::Draft);
    phase618Invoice($other, InvoiceStatus::Draft);
    $tenant = app(CurrentTenant::class);

    $tenant->clear();
    Artisan::call('billing:health');
    expect($tenant->id())->toBeNull();

    $tenant->set($other->id);
    Artisan::call('billing:health', ['--company' => $company->id]);
    expect($tenant->id())->toBe($other->id);
});

test('formato tabla muestra resumen y hallazgos con accion sugerida', function () {
    $company = Company::factory()->create();
    phase618Invoice($company, InvoiceStatus::Issued);

    $this->artisan('billing:health')
        ->expectsOutputToContain('sin escrituras ni llamadas al PAC')
        ->expectsOutputToContain('erp_issued_without_cfdi→operations_issue')
        ->assertExitCode(0);
});
