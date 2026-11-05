<?php

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Jobs\Billing\DownloadInvoiceArtifactsJob;
use App\Jobs\Billing\SendInvoiceEmailJob;
use App\Models\Company;
use App\Models\FacturapiOrganization;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Billing\DownloadInvoiceArtifactsService;
use App\Services\Billing\InvoiceBusinessCapabilitiesService;
use App\Services\Billing\InvoiceHealthEvaluator;
use App\Services\Billing\SendInvoiceEmailService;
use App\Support\Billing\PacEnvironment;
use App\Support\Billing\PacEnvironmentAccess;
use App\Support\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fase 6.31 (4): el ambiente de la factura se fija con su primera reserva,
 * bajo lock, y después nunca cambia. Una factura de otro ambiente (o con
 * operaciones previas sin ambiente) no se opera.
 */
beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'billing.pac_mode' => 'test',
    ]);
    Http::preventStrayRequests();
});

/** @return array{Company, User, Invoice} */
function phase631BindingInvoice(array $overrides = []): array
{
    $company = Company::factory()->withFacturapiOrganization('sk_test_PHASE_631_BINDING_KEY')->create();
    $user = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Admin]);
    $invoice = Invoice::factory()->create(array_merge([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Ready,
        'subtotal' => 100,
        'tax_total' => 0,
        'total' => 100,
        'pac_environment' => null,
    ], $overrides));
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id, 'subtotal' => 100, 'total' => 100]);
    app(CurrentTenant::class)->set($company->id);

    return [$company, $user, $invoice->fresh()];
}

function phase631IssuedAttributes(string $environment): array
{
    return [
        'status' => InvoiceStatus::Issued,
        'pac_environment' => $environment,
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_631_binding',
        'cfdi_uuid' => 'AAAAAAAA-1111-4222-8333-444444444444',
        'pac_status' => 'valid',
        'pac_issue_status' => 'succeeded',
        'cancellation_status' => 'none',
    ];
}

function phase631IssueFake(string $draftId): void
{
    Http::fake(function (Request $request) use ($draftId) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $draft = ['id' => $draftId, 'status' => 'draft', 'livemode' => false, 'is_ready_to_stamp' => true, 'total' => 100];

        return match (true) {
            $request->method() === 'POST' && $path === '/v2/invoices' => Http::response($draft),
            $request->method() === 'GET' && $path === "/v2/invoices/{$draftId}" => Http::response($draft),
            $request->method() === 'POST' && $path === "/v2/invoices/{$draftId}/stamp" => Http::response([
                'id' => $draftId, 'status' => 'valid', 'livemode' => false,
                'uuid' => 'AAAAAAAA-1111-4222-8333-444444444444', 'stamp' => ['date' => '2026-08-14T12:00:00Z'],
            ]),
            default => Http::response([], 599),
        };
    });
}

test('la primera reserva fija el ambiente de la empresa', function () {
    [, $user, $invoice] = phase631BindingInvoice();
    phase631IssueFake('inv_631_bind_'.$invoice->id);
    app(CurrentTenant::class)->clear();

    expect($invoice->pac_environment)->toBeNull();

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/operations/issue", ['confirm' => true])
        ->assertOk()
        ->assertJsonPath('pac.mode', 'test')
        ->assertJsonPath('pac.environment_status', PacEnvironmentAccess::BOUND);

    expect(DB::table('invoices')->where('id', $invoice->id)->value('pac_environment'))->toBe('test');
});

test('una falla de la consulta de folio no deja la factura atada a un ambiente', function () {
    config(['billing.cfdi_folio_check' => true]);
    [, $user, $invoice] = phase631BindingInvoice();
    Http::fake(['example-pac.test/v2/invoices*' => Http::response(['message' => 'error'], 500)]);
    app(CurrentTenant::class)->clear();

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/operations/issue", ['confirm' => true])
        ->assertStatus(503);

    $row = DB::table('invoices')->where('id', $invoice->id)->first();

    expect($row->status)->toBe('ready')
        ->and($row->pac_environment)->toBeNull()
        ->and($row->pac_draft_idempotency_key)->toBeNull();
});

test('una factura ready sin reserva opera con el ambiente actual de su empresa', function () {
    [$company, , $invoice] = phase631BindingInvoice();

    expect(PacEnvironmentAccess::statusFor($invoice))->toBeNull();

    phase631MakeLive($company->id);

    expect(PacEnvironmentAccess::statusFor($invoice->fresh()))->toBeNull()
        ->and(PacEnvironment::forInvoice($invoice->fresh()))->toBe('live');
});

test('una factura fijada en Test no se opera cuando su empresa pasa a Live', function () {
    [$company, $user, $invoice] = phase631BindingInvoice(phase631IssuedAttributes('test'));
    phase631MakeLive($company->id);
    DB::table('invoices')->where('id', $invoice->id)->update(['pac_environment' => 'test']);
    app(CurrentTenant::class)->clear();

    foreach (['pac/reconcile', 'pac/artifacts', 'operations/email'] as $action) {
        $this->actingAs($user, 'api')
            ->postJson("/api/invoices/{$invoice->id}/{$action}", ['confirm' => true])
            ->assertStatus(409)
            ->assertJsonPath('code', 'PAC_ENVIRONMENT_MISMATCH');
    }

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/operations/cancel", ['confirm' => true, 'motive' => '02'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'PAC_ENVIRONMENT_MISMATCH');

    $billing = $this->actingAs($user, 'api')->getJson("/api/invoices/{$invoice->id}/billing")->assertOk();

    expect($billing->json('actions'))->each->not->toBeTrue()
        ->and($billing->json('pac.environment_status'))->toBe(PacEnvironmentAccess::MISMATCH);

    app(CurrentTenant::class)->set($company->id);
    expect(DB::table('invoices')->where('id', $invoice->id)->value('pac_cancel_request_status'))->toBeNull();
    Http::assertNothingSent();
});

test('una reserva previa sin ambiente se trata como mismatch', function () {
    [, $user, $invoice] = phase631BindingInvoice([
        'status' => InvoiceStatus::Issued,
        'pac_draft_idempotency_key' => 'erp-draft:1:1:v1',
    ]);
    app(CurrentTenant::class)->clear();

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/pac/reconcile")
        ->assertStatus(409)
        ->assertJsonPath('code', 'PAC_ENVIRONMENT_MISMATCH');

    app(CurrentTenant::class)->set($invoice->company_id);
    $fresh = $invoice->fresh();

    expect(PacEnvironmentAccess::statusFor($fresh))->toBe(PacEnvironmentAccess::UNBOUND)
        ->and(app(InvoiceBusinessCapabilitiesService::class)->for($fresh, $user))->each->not->toBeTrue()
        ->and(app(InvoiceHealthEvaluator::class)->evaluate($fresh, CarbonImmutable::now())->find('pac_environment_mismatch'))->not->toBeNull()
        ->and($fresh->pac_environment)->toBeNull();
    Http::assertNothingSent();
});

test('billing:health reporta pac_environment_mismatch y sustituye los demás hallazgos', function () {
    [$company, , $invoice] = phase631BindingInvoice(array_merge(phase631IssuedAttributes('test'), ['pac_reconciliation_required' => true]));
    phase631MakeLive($company->id);
    DB::table('invoices')->where('id', $invoice->id)->update(['pac_environment' => 'test']);
    app(CurrentTenant::class)->clear();

    Artisan::call('billing:health', ['--format' => 'json', '--fail-on' => 'none']);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($report['by_code'])->toBe(['pac_environment_mismatch' => 1]);
});

test('los jobs revalidan el ambiente y fallan sin reintentar ni tocar la factura', function (string $job) {
    [$company, , $invoice] = phase631BindingInvoice(phase631IssuedAttributes('test'));
    phase631MakeLive($company->id);
    DB::table('invoices')->where('id', $invoice->id)->update(['pac_environment' => 'test']);
    $company->forceFill(['auto_email_invoices' => true])->save();
    Log::spy();

    $instance = new $job($company->id, $invoice->id);
    $instance->withFakeQueueInteractions()->handle(...array_map(fn (string $class) => app($class), $job === DownloadInvoiceArtifactsJob::class
        ? [DownloadInvoiceArtifactsService::class, InvoiceBusinessCapabilitiesService::class, CurrentTenant::class]
        : [SendInvoiceEmailService::class, CurrentTenant::class]));

    $instance->assertFailed();
    expect(DB::table('invoices')->where('id', $invoice->id)->value('cfdi_artifacts_status'))->toBeNull()
        ->and(DB::table('invoice_pac_events')->where('invoice_id', $invoice->id)->count())->toBe(0);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'billing.pac.guard_rejected' && $context['code'] === 'pac_environment_mismatch');
    Http::assertNothingSent();
})->with([DownloadInvoiceArtifactsJob::class, SendInvoiceEmailJob::class]);

test('paro de emergencia: con el techo en test nada de una empresa Live contacta al PAC', function () {
    [$company, $user, $invoice] = phase631BindingInvoice(phase631IssuedAttributes('live'));
    phase631MakeLive($company->id);
    config(['billing.pac_mode' => 'test']);
    app(CurrentTenant::class)->clear();

    foreach (['pac/reconcile', 'pac/artifacts', 'operations/email'] as $action) {
        $this->actingAs($user, 'api')
            ->postJson("/api/invoices/{$invoice->id}/{$action}", ['confirm' => true])
            ->assertStatus(503)
            ->assertJsonPath('code', 'PAC_CONFIGURATION_INVALID');
    }

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/operations/cancel", ['confirm' => true, 'motive' => '02'])
        ->assertStatus(503);

    $job = new DownloadInvoiceArtifactsJob($company->id, $invoice->id);
    $job->withFakeQueueInteractions()->handle(app(DownloadInvoiceArtifactsService::class), app(InvoiceBusinessCapabilitiesService::class), app(CurrentTenant::class));
    $job->assertFailed();

    $row = DB::table('invoices')->where('id', $invoice->id)->first();
    expect($row->pac_cancel_request_status)->toBeNull()
        ->and($row->cfdi_artifacts_status)->toBeNull()
        ->and((bool) $row->pac_reconciliation_required)->toBeFalse()
        ->and($row->pac_last_error)->toBeNull()
        ->and($row->status)->toBe('issued')
        ->and(DB::table('invoice_pac_events')->where('invoice_id', $invoice->id)->count())->toBe(0);
    Http::assertNothingSent();
});

test('billing:backfill-pac-environment deduce el ambiente de las respuestas guardadas', function () {
    [, , $test] = phase631BindingInvoice(['pac_external_id' => 'inv_a']);
    $test->forceFill(['pac_response' => ['id' => 'inv_a', 'livemode' => false]])->save();
    $live = Invoice::factory()->create(['company_id' => $test->company_id, 'pac_environment' => null, 'pac_external_id' => 'inv_b']);
    $live->forceFill(['pac_draft_response' => ['id' => 'inv_b', 'livemode' => true]])->save();
    $conflict = Invoice::factory()->create(['company_id' => $test->company_id, 'pac_environment' => null, 'pac_external_id' => 'inv_c']);
    $conflict->forceFill(['pac_response' => ['livemode' => false], 'pac_draft_response' => ['livemode' => true]])->save();
    $untouched = Invoice::factory()->create(['company_id' => $test->company_id, 'pac_environment' => null]);
    DB::table('invoices')->update(['updated_at' => '2026-10-01 00:00:00']);

    expect(Artisan::call('billing:backfill-pac-environment'))->toBe(0)
        ->and(DB::table('invoices')->whereNotNull('pac_environment')->count())->toBe(0);

    expect(Artisan::call('billing:backfill-pac-environment', ['--apply' => true]))->toBe(0);

    $environments = DB::table('invoices')->pluck('pac_environment', 'id');
    expect($environments[$test->id])->toBe('test')
        ->and($environments[$live->id])->toBe('live')
        ->and($environments[$conflict->id])->toBeNull()
        ->and($environments[$untouched->id])->toBeNull()
        ->and(DB::table('invoices')->where('updated_at', '!=', '2026-10-01 00:00:00')->count())->toBe(0);
});

test('--assume-test fija en test lo que no tiene evidencia y lo lista', function () {
    [, , $invoice] = phase631BindingInvoice(['status' => InvoiceStatus::Issued, 'pac_draft_idempotency_key' => 'erp-draft:9:9:v1']);

    expect(Artisan::call('billing:backfill-pac-environment', ['--apply' => true]))->toBe(0)
        ->and(DB::table('invoices')->where('id', $invoice->id)->value('pac_environment'))->toBeNull();

    expect(Artisan::call('billing:backfill-pac-environment', ['--apply' => true, '--assume-test' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('ASUMIDO (--assume-test)')->toContain($invoice->folio)
        ->and(DB::table('invoices')->where('id', $invoice->id)->value('pac_environment'))->toBe('test');
});

test('--assume-test aborta sin escribir si hay rastros de Live', function (string $trace) {
    [$company, , $invoice] = phase631BindingInvoice(['status' => InvoiceStatus::Issued, 'pac_draft_idempotency_key' => 'erp-draft:9:9:v1']);

    if ($trace === 'respuesta livemode=true') {
        $other = Invoice::factory()->create(['company_id' => $company->id, 'pac_environment' => 'live', 'pac_external_id' => 'inv_l']);
        $other->forceFill(['pac_response' => ['livemode' => true]])->save();
    } else {
        FacturapiOrganization::withoutGlobalScopes()->where('company_id', $company->id)->firstOrFail()
            ->setLiveSecretKey('sk_live_PHASE_631_TRACE_KEY_000001')->save();
    }

    expect(Artisan::call('billing:backfill-pac-environment', ['--apply' => true, '--assume-test' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('abortado')->not->toContain('sk_live_PHASE')
        ->and(DB::table('invoices')->where('id', $invoice->id)->value('pac_environment'))->toBeNull();
})->with(['respuesta livemode=true', 'llave Live guardada']);
