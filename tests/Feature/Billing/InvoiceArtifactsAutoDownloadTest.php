<?php

use App\Enums\InvoiceHealthAction;
use App\Enums\InvoiceHealthStatus;
use App\Enums\InvoiceStatus;
use App\Exceptions\Billing\PacUnavailableException;
use App\Jobs\Billing\DownloadInvoiceArtifactsJob;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\Billing\DownloadInvoiceArtifactsService;
use App\Services\Billing\InvoiceBusinessCapabilitiesService;
use App\Services\Billing\InvoiceHealthEvaluator;
use App\Services\Billing\StampPacDraftInvoiceService;
use App\Support\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Fase 6.21: tras un timbrado exitoso, InvoiceIssued encola
 * DownloadInvoiceArtifactsJob. Un fallo de la descarga nunca altera la
 * emisión. phpunit.xml apaga billing.artifacts.auto_download; estas
 * pruebas lo activan explícitamente.
 */
const PHASE621_UUID = 'aaaaaaaa-1111-4222-8333-444444444444';

beforeEach(function () {
    facturapiOrganizationsFromConfigKey();
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_PHASE_621_QUEUE',
        'billing.artifacts.auto_download' => true,
        'billing.artifacts.pending_minutes' => 10,
    ]);

    Storage::fake('local');
    Http::preventStrayRequests();
});

function phase621DraftReadyInvoice(): Invoice
{
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_draft_external_id' => 'inv_auto_'.$invoice->id,
        'pac_draft_idempotency_key' => "erp-invoice-draft:{$company->id}:{$invoice->id}:v1",
        'pac_draft_status' => 'draft',
        'pac_draft_ready_to_stamp' => true,
    ])->save();
    app(CurrentTenant::class)->set($company->id);

    return $invoice->fresh();
}

function phase621StampedForJob(array $overrides = []): Invoice
{
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    $invoice->forceFill(array_merge([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_job_'.$invoice->id,
        'cfdi_uuid' => PHASE621_UUID,
        'pac_status' => 'valid',
        'pac_issue_status' => 'succeeded',
        'stamped_at' => now(),
    ], $overrides))->save();

    return $invoice->fresh();
}

/** Simula sync del borrador, /stamp y, opcionalmente, /xml y /pdf. */
function phase621FakeStampAndArtifacts(string $remoteId, int $xmlStatus = 200): void
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?><cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" Version="4.0">'
        .'<cfdi:Complemento><tfd:TimbreFiscalDigital xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" Version="1.1" UUID="'.PHASE621_UUID.'"/>'
        .'</cfdi:Complemento></cfdi:Comprobante>';

    Http::fake([
        "*/invoices/{$remoteId}/stamp" => Http::response([
            'id' => $remoteId, 'status' => 'valid', 'livemode' => false,
            'uuid' => PHASE621_UUID, 'stamp' => ['date' => '2026-10-20T13:52:54'],
        ]),
        "*/invoices/{$remoteId}/xml" => $xmlStatus === 200
            ? Http::response($xml, 200)
            : Http::response(['message' => 'PAC', 'code' => 'remote_error'], $xmlStatus),
        "*/invoices/{$remoteId}/pdf" => Http::response("%PDF-1.4\nfake\n%%EOF", 200),
        "*/invoices/{$remoteId}" => Http::response([
            'id' => $remoteId, 'status' => 'draft', 'livemode' => false, 'is_ready_to_stamp' => true,
        ]),
    ]);
}

function phase621RunJob(Invoice $invoice): DownloadInvoiceArtifactsJob
{
    $job = (new DownloadInvoiceArtifactsJob((int) $invoice->company_id, (int) $invoice->id))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

// ==================== Migración ====================

test('la migración agrega cfdi_artifacts_started_at nullable', function () {
    expect(Schema::hasColumn('invoices', 'cfdi_artifacts_started_at'))->toBeTrue();

    $invoice = phase621StampedForJob();
    expect($invoice->cfdi_artifacts_started_at)->toBeNull();
});

// ==================== Listener ====================

test('un timbrado exitoso encola una sola descarga con los ids de la factura', function () {
    Queue::fake();
    $invoice = phase621DraftReadyInvoice();
    phase621FakeStampAndArtifacts($invoice->pac_draft_external_id);

    $stamped = app(StampPacDraftInvoiceService::class)->stamp($invoice);

    expect($stamped->pac_issue_status)->toBe('succeeded');
    Queue::assertPushed(DownloadInvoiceArtifactsJob::class, 1);
    Queue::assertPushed(DownloadInvoiceArtifactsJob::class, fn (DownloadInvoiceArtifactsJob $job): bool => $job->companyId === $invoice->company_id
        && $job->invoiceId === $invoice->id);
});

test('con auto_download apagado no se encola nada', function () {
    config(['billing.artifacts.auto_download' => false]);
    Queue::fake();
    $invoice = phase621DraftReadyInvoice();
    phase621FakeStampAndArtifacts($invoice->pac_draft_external_id);

    app(StampPacDraftInvoiceService::class)->stamp($invoice);

    Queue::assertNothingPushed();
});

test('con cola sync, el timbrado deja el XML/PDF almacenados sin acción manual', function () {
    $invoice = phase621DraftReadyInvoice();
    phase621FakeStampAndArtifacts($invoice->pac_draft_external_id);

    app(StampPacDraftInvoiceService::class)->stamp($invoice);

    $fresh = $invoice->fresh();
    expect($fresh->pac_issue_status)->toBe('succeeded')
        ->and($fresh->cfdi_artifacts_status)->toBe('stored')
        ->and(Storage::disk('local')->exists($fresh->cfdi_xml_path))->toBeTrue();
});

test('una descarga fallida nunca marca la emisión como fallida', function () {
    $invoice = phase621DraftReadyInvoice();
    phase621FakeStampAndArtifacts($invoice->pac_draft_external_id, xmlStatus: 500);

    $stamped = app(StampPacDraftInvoiceService::class)->stamp($invoice);

    $fresh = $invoice->fresh();
    expect($stamped->pac_issue_status)->toBe('succeeded')
        ->and($fresh->pac_issue_status)->toBe('succeeded')
        ->and($fresh->pac_status)->toBe('valid')
        ->and($fresh->pac_reconciliation_required)->toBeFalse()
        ->and($fresh->cfdi_artifacts_status)->toBe('reconciliation_required');
});

// ==================== Job ====================

test('el job es único por factura y reintenta con backoff', function () {
    $job = new DownloadInvoiceArtifactsJob(7, 42);

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('7:42')
        ->and($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([60, 300, 900]);
});

test('el job fija el tenant de la factura y restaura el previo', function (bool $hadPreviousTenant) {
    $invoice = phase621StampedForJob();
    phase621FakeStampAndArtifacts($invoice->pac_external_id);
    $tenant = app(CurrentTenant::class);
    $other = Company::factory()->create();
    $hadPreviousTenant ? $tenant->set($other->id) : $tenant->clear();

    phase621RunJob($invoice)->assertNotFailed();

    expect($tenant->id())->toBe($hadPreviousTenant ? $other->id : null);
    $tenant->set($invoice->company_id);
    expect($invoice->fresh()->cfdi_artifacts_status)->toBe('stored');
})->with([
    'con tenant previo' => [true],
    'sin tenant previo' => [false],
]);

test('el job no hace nada si los artifacts ya están almacenados', function () {
    $invoice = phase621StampedForJob(['cfdi_artifacts_status' => 'stored']);
    Http::fake();

    phase621RunJob($invoice)->assertNotFailed();

    Http::assertNothingSent();
});

test('un fallo definitivo marca el job como fallido sin reintentar', function () {
    $invoice = phase621StampedForJob();
    phase621FakeStampAndArtifacts($invoice->pac_external_id, xmlStatus: 400);

    phase621RunJob($invoice)->assertFailed();

    app(CurrentTenant::class)->set($invoice->company_id);
    expect($invoice->fresh()->cfdi_artifacts_status)->toBe('failed');
});

test('un fallo ambiguo se relanza para que la cola reintente', function () {
    $invoice = phase621StampedForJob();
    phase621FakeStampAndArtifacts($invoice->pac_external_id, xmlStatus: 500);

    expect(fn () => phase621RunJob($invoice))->toThrow(PacUnavailableException::class);

    app(CurrentTenant::class)->set($invoice->company_id);
    expect($invoice->fresh()->cfdi_artifacts_status)->toBe('reconciliation_required');
});

// ==================== Reserva pending ====================

test('una reserva pending vencida o sin fecha puede retomarse; una vigente no', function (?int $minutesAgo, bool $resumable) {
    $invoice = phase621StampedForJob([
        'cfdi_artifacts_status' => 'pending',
        'cfdi_artifacts_started_at' => $minutesAgo === null ? null : now()->subMinutes($minutesAgo),
    ]);
    app(CurrentTenant::class)->set($invoice->company_id);
    phase621FakeStampAndArtifacts($invoice->pac_external_id);

    expect(app(InvoiceBusinessCapabilitiesService::class)->artifactsFetchable($invoice))->toBe($resumable);

    if ($resumable) {
        app(DownloadInvoiceArtifactsService::class)->download($invoice);
        expect($invoice->fresh()->cfdi_artifacts_status)->toBe('stored')
            ->and($invoice->fresh()->cfdi_artifacts_started_at)->not->toBeNull();
    } else {
        expect(fn () => app(DownloadInvoiceArtifactsService::class)->download($invoice))
            ->toThrow(RuntimeException::class);
        Http::assertNothingSent();
    }
})->with([
    'vigente (2 min)' => [2, false],
    'vencida (30 min)' => [30, true],
    'sin fecha (anterior a la columna)' => [null, true],
]);

// ==================== billing:health ====================

test('billing:health distingue una descarga en curso de una abandonada', function (int $minutesAgo, string $code, InvoiceHealthStatus $status, InvoiceHealthAction $action) {
    $now = CarbonImmutable::parse('2026-10-21 12:00:00');
    $invoice = phase621StampedForJob([
        'cfdi_artifacts_status' => 'pending',
        'cfdi_artifacts_started_at' => $now->subMinutes($minutesAgo),
    ]);

    $report = app(InvoiceHealthEvaluator::class)->evaluate($invoice, $now);
    $finding = $report->find($code);

    expect($finding)->not->toBeNull()
        ->and($finding->status)->toBe($status)
        ->and($finding->action)->toBe($action)
        ->and($finding->ageMinutes)->toBe($minutesAgo)
        ->and($report->codes())->not->toContain('cfdi_artifacts_missing');
})->with([
    'en curso' => [3, 'artifacts_download_pending', InvoiceHealthStatus::Transient, InvoiceHealthAction::Wait],
    'abandonada' => [45, 'artifacts_download_pending_stale', InvoiceHealthStatus::Recoverable, InvoiceHealthAction::FetchArtifacts],
]);
