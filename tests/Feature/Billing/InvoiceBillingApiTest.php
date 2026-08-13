<?php

use App\Contracts\Billing\PacProvider;
use App\Enums\InvoicePacEventType;
use App\Enums\InvoiceStatus;
use App\Http\Resources\Billing\InvoicePacEventResource;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\InvoicePacAuditService;
use App\Support\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;

beforeEach(function () {
    config(['services.facturapi.test_key' => 'sk_test_BILLING_API_SECRET']);
    Http::preventStrayRequests();
    Http::fake();

    $this->mock(PacProvider::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('name');
        $mock->shouldNotReceive('createInvoice');
        $mock->shouldNotReceive('retrieveInvoice');
        $mock->shouldNotReceive('findInvoiceByExternalId');
        $mock->shouldNotReceive('createDraftInvoice');
        $mock->shouldNotReceive('retrieveDraftInvoice');
        $mock->shouldNotReceive('updateDraftInvoice');
        $mock->shouldNotReceive('stampDraftInvoice');
        $mock->shouldNotReceive('downloadXml');
        $mock->shouldNotReceive('downloadPdf');
        $mock->shouldNotReceive('cancelInvoice');
        $mock->shouldNotReceive('downloadCancellationReceiptXml');
        $mock->shouldNotReceive('downloadCancellationReceiptPdf');
    });
});

/** @return array{0: Company, 1: User, 2: Invoice} */
function billingApiFixture(array $overrides = []): array
{
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Issued,
    ]);
    $invoice->forceFill(array_merge([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_private_billing_'.$invoice->id,
        'cfdi_uuid' => '96013e83-154b-4153-8e61-c38b8966e560',
        'pac_status' => 'canceled',
        'cancellation_status' => 'accepted',
        'pac_issue_status' => 'succeeded',
        'pac_reconciliation_required' => false,
        'stamped_at' => '2026-08-01 10:00:00',
        'last_pac_sync_at' => '2026-08-02 10:00:00',
        'pac_response' => ['raw_secret' => 'never expose'],
        'pac_last_error' => 'internal PAC diagnostic',
        'pac_draft_response' => ['raw_draft_secret' => 'never expose'],
        'cfdi_artifacts_status' => 'stored',
        'cfdi_xml_path' => 'cfdi/private/invoice.xml',
        'cfdi_pdf_path' => 'cfdi/private/invoice.pdf',
        'cfdi_xml_sha256' => str_repeat('a', 64),
        'cfdi_pdf_sha256' => str_repeat('b', 64),
        'cfdi_artifacts_downloaded_at' => '2026-08-03 10:00:00',
        'cancellation_receipt_status' => 'reconciliation_required',
        'cancellation_receipt_last_error' => '[CANCELLATION_RECEIPT_UUID_MISMATCH] Diagnóstico privado con UUID CF5138A2-1111-2222-3333-444444442E90.',
    ], $overrides))->save();

    app(CurrentTenant::class)->set($company->id);

    return [$company, $user, $invoice->fresh()];
}

test('endpoint autenticado expone snapshot fiscal explícito compatible con Invoice 2 sin consultar PAC', function () {
    [$company, $user, $invoice] = billingApiFixture();

    CarbonImmutable::setTestNow('2026-08-04 10:00:00');
    app(InvoicePacAuditService::class)->append($invoice, InvoicePacEventType::CancellationReceiptAttempted, [
        'cancellation_receipt_status' => 'pending',
    ]);
    CarbonImmutable::setTestNow('2026-08-04 10:01:00');
    app(InvoicePacAuditService::class)->append($invoice, InvoicePacEventType::CancellationReceiptIdentityMismatch, [
        'receipt_uuid_count' => 1,
        'expected_uuid_masked' => '96013e83...e560',
        'pac_external_id_masked' => 'inv_priv...0001',
        'elapsed_ms' => 25,
    ], 'CANCELLATION_RECEIPT_UUID_MISMATCH');
    CarbonImmutable::setTestNow();
    app(CurrentTenant::class)->clear();

    $response = $this->actingAs($user, 'api')
        ->getJson("/api/invoices/{$invoice->id}/billing")
        ->assertOk()
        ->assertExactJsonStructure([
            'id', 'folio', 'status',
            'pac' => [
                'provider', 'status', 'cancellation_status', 'issue_status',
                'reconciliation_required', 'last_sync_at',
            ],
            'cfdi' => [
                'uuid', 'stamped_at',
                'artifacts' => ['status', 'xml_available', 'pdf_available', 'downloaded_at'],
            ],
            'cancellation_receipt' => ['status', 'available', 'downloaded_at', 'error_code'],
            'timeline' => [
                '*' => [
                    'id', 'type', 'occurred_at', 'pac_status',
                    'cancellation_status', 'issue_status', 'pac_code', 'context',
                ],
            ],
        ])
        ->assertJsonPath('id', $invoice->id)
        ->assertJsonPath('folio', $invoice->folio)
        ->assertJsonPath('status', 'issued')
        ->assertJsonPath('pac.provider', 'facturapi')
        ->assertJsonPath('pac.status', 'canceled')
        ->assertJsonPath('pac.cancellation_status', 'accepted')
        ->assertJsonPath('pac.issue_status', 'succeeded')
        ->assertJsonPath('pac.reconciliation_required', false)
        ->assertJsonPath('cfdi.uuid', '96013e83-154b-4153-8e61-c38b8966e560')
        ->assertJsonPath('cfdi.artifacts.status', 'stored')
        ->assertJsonPath('cfdi.artifacts.xml_available', true)
        ->assertJsonPath('cfdi.artifacts.pdf_available', true)
        ->assertJsonPath('cancellation_receipt.status', 'reconciliation_required')
        ->assertJsonPath('cancellation_receipt.available', false)
        ->assertJsonPath('cancellation_receipt.error_code', 'CANCELLATION_RECEIPT_UUID_MISMATCH')
        ->assertJsonPath('timeline.0.type', 'cancellation_receipt_attempted')
        ->assertJsonPath('timeline.1.type', 'cancellation_receipt_identity_mismatch')
        ->assertJsonPath('timeline.1.context', [
            'receipt_uuid_count' => 1,
            'expected_uuid_masked' => '96013e83...e560',
            'pac_external_id_masked' => 'inv_priv...0001',
            'elapsed_ms' => 25,
        ]);

    expect($response->json('timeline.0'))->not->toHaveKeys(['company_id', 'invoice_id'])
        ->and($company->id)->toBe($invoice->company_id);

    Http::assertNothingSent();
});

test('requiere autenticación y falla cerrado para invoice inexistente u otro tenant', function () {
    [, $owner, $invoice] = billingApiFixture();
    $foreignCompany = Company::factory()->create();
    $foreignUser = User::factory()->create(['company_id' => $foreignCompany->id]);
    app(CurrentTenant::class)->clear();

    $this->getJson("/api/invoices/{$invoice->id}/billing")->assertUnauthorized();
    $this->actingAs($foreignUser, 'api')
        ->getJson("/api/invoices/{$invoice->id}/billing")
        ->assertNotFound();
    $this->actingAs($owner, 'api')
        ->getJson('/api/invoices/999999999/billing')
        ->assertNotFound();

    Http::assertNothingSent();
});

test('aplica InvoicePolicy además del CompanyScope', function () {
    [, $user, $invoice] = billingApiFixture();
    app(CurrentTenant::class)->clear();
    Gate::before(fn (): bool => false);

    $this->actingAs($user, 'api')
        ->getJson("/api/invoices/{$invoice->id}/billing")
        ->assertForbidden();

    Http::assertNothingSent();
});

test('flags available requieren status stored y metadata de ambos archivos sin leer Storage', function () {
    [, $user, $invoice] = billingApiFixture([
        'cfdi_pdf_path' => null,
        'cancellation_receipt_status' => 'stored',
        'cancellation_receipt_xml_path' => 'receipts/private/receipt.xml',
        'cancellation_receipt_pdf_path' => 'receipts/private/receipt.pdf',
        'cancellation_receipt_downloaded_at' => '2026-08-05 10:00:00',
        'cancellation_receipt_last_error' => null,
    ]);
    app(CurrentTenant::class)->clear();

    $this->actingAs($user, 'api')
        ->getJson("/api/invoices/{$invoice->id}/billing")
        ->assertOk()
        ->assertJsonPath('cfdi.artifacts.xml_available', true)
        ->assertJsonPath('cfdi.artifacts.pdf_available', false)
        ->assertJsonPath('cancellation_receipt.available', true)
        ->assertJsonPath('cancellation_receipt.error_code', null);

    Http::assertNothingSent();
});

test('contrato nunca expone snapshots crudos errores paths hashes secretos XML PDF ni UUID ajeno', function () {
    [, $user, $invoice] = billingApiFixture();
    app(CurrentTenant::class)->clear();

    $content = $this->actingAs($user, 'api')
        ->getJson("/api/invoices/{$invoice->id}/billing")
        ->assertOk()
        ->getContent();

    expect($content)->not->toContain(
        'pac_response', 'pac_draft_response', 'pac_last_error',
        'cancellation_receipt_last_error', 'cfdi_xml_path', 'cfdi_pdf_path',
        'cancellation_receipt_xml_path', 'cancellation_receipt_pdf_path',
        'cfdi_xml_sha256', 'cfdi_pdf_sha256', 'raw_secret', 'raw_draft_secret',
        'internal PAC diagnostic', 'cfdi/private', 'receipts/private',
        'CF5138A2-1111-2222-3333-444444442E90',
        'sk_test_BILLING_API_SECRET', 'Authorization', '<Acuse', '%PDF-',
    );

    Http::assertNothingSent();
});

test('timeline realista ordena ascendente y aplica whitelist por cada tipo', function () {
    [, $user, $invoice] = billingApiFixture();

    $fixtures = [
        [InvoicePacEventType::DraftCreated, ['is_ready_to_stamp' => false, 'remote_total' => 116, 'unexpected' => 'hidden']],
        [InvoicePacEventType::StampSucceeded, ['attempt' => 1, 'elapsed_ms' => 200, 'result_status' => 'valid', 'unexpected' => 'hidden']],
        [InvoicePacEventType::ArtifactsStored, ['xml_size' => 4500, 'pdf_size' => 98000, 'xml_sha256' => str_repeat('a', 64)]],
        [InvoicePacEventType::CancellationAccepted, ['motive' => '02', 'elapsed_ms' => 300, 'result_status' => 'canceled']],
        [InvoicePacEventType::CancellationReceiptIdentityMismatch, ['receipt_uuid_count' => 1, 'expected_uuid_masked' => '96013e83...e560', 'unexpected' => 'hidden']],
    ];

    foreach ($fixtures as $offset => [$type, $context]) {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-06 10:00:00')->addMinutes($offset));
        app(InvoicePacAuditService::class)->append($invoice, $type, $context);
    }

    CarbonImmutable::setTestNow();
    app(CurrentTenant::class)->clear();

    $timeline = $this->actingAs($user, 'api')
        ->getJson("/api/invoices/{$invoice->id}/billing")
        ->assertOk()
        ->json('timeline');

    expect(array_column($timeline, 'type'))->toBe([
        'draft_created',
        'stamp_succeeded',
        'artifacts_stored',
        'cancellation_accepted',
        'cancellation_receipt_identity_mismatch',
    ])->and($timeline[0]['context'])->toBe([
        'is_ready_to_stamp' => false,
        'remote_total' => 116,
    ])->and($timeline[1]['context'])->toBe([
        'attempt' => 1,
        'elapsed_ms' => 200,
        'result_status' => 'valid',
    ])->and($timeline[2]['context'])->toBe([
        'xml_size' => 4500,
        'pdf_size' => 98000,
    ])->and($timeline[3]['context'])->toBe([
        'motive' => '02',
        'elapsed_ms' => 300,
        'result_status' => 'canceled',
    ])->and($timeline[4]['context'])->toBe([
        'receipt_uuid_count' => 1,
        'expected_uuid_masked' => '96013e83...e560',
    ]);

    expect(json_encode($timeline))->not->toContain('unexpected', str_repeat('a', 64));
    Http::assertNothingSent();
});

test('segunda barrera sanitiza valores legacy incluso dentro de claves permitidas', function () {
    [, $user, $invoice] = billingApiFixture();
    $event = app(InvoicePacAuditService::class)->append($invoice, InvoicePacEventType::StampFailed, [
        'reason' => 'safe',
    ]);
    DB::table('invoice_pac_events')->where('id', $event->id)->update([
        'context' => Crypt::encryptString(json_encode([
            'reason' => 'Bearer sk_test_BILLING_API_SECRET UUID CF5138A2-1111-2222-3333-444444442E90',
            'unknown_payload' => '<Acuse>secret</Acuse>',
        ], JSON_THROW_ON_ERROR)),
    ]);
    app(CurrentTenant::class)->clear();

    $content = $this->actingAs($user, 'api')
        ->getJson("/api/invoices/{$invoice->id}/billing")
        ->assertOk()
        ->getContent();

    expect($content)->not->toContain(
        'sk_test_BILLING_API_SECRET', 'Bearer',
        'CF5138A2-1111-2222-3333-444444442E90',
        'unknown_payload', '<Acuse>',
    )->and($content)->toContain('CF5138A2...2E90');

    Http::assertNothingSent();
});

test('tipo de enum futuro sin mapper devuelve context vacío', function () {
    $resource = new InvoicePacEventResource((object) [
        'id' => 1,
        'event_type' => InvoiceStatus::Issued,
        'occurred_at' => CarbonImmutable::parse('2026-08-07 10:00:00'),
        'pac_status' => 'valid',
        'cancellation_status' => null,
        'pac_issue_status' => 'succeeded',
        'pac_code' => null,
        'context' => ['secret_future_key' => 'must not escape'],
    ]);

    expect($resource->resolve(request())['context'])->toBe([]);
});

test('timeline usa default 50 y aplica máximo 100 conservando los eventos más recientes', function () {
    [$company, $user, $invoice] = billingApiFixture();
    $base = CarbonImmutable::parse('2026-08-08 10:00:00');
    $rows = [];

    foreach (range(1, 120) as $sequence) {
        $occurredAt = $base->addSeconds($sequence);
        $rows[] = [
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
            'event_type' => InvoicePacEventType::IssueAttempted->value,
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
        ];
    }

    DB::table('invoice_pac_events')->insert($rows);
    $firstId = DB::table('invoice_pac_events')->min('id');
    $lastId = DB::table('invoice_pac_events')->max('id');
    app(CurrentTenant::class)->clear();

    $defaultTimeline = $this->actingAs($user, 'api')
        ->getJson("/api/invoices/{$invoice->id}/billing")
        ->assertOk()
        ->json('timeline');
    $maxTimeline = $this->actingAs($user, 'api')
        ->getJson("/api/invoices/{$invoice->id}/billing?timeline_limit=999999")
        ->assertOk()
        ->json('timeline');

    expect($defaultTimeline)->toHaveCount(50)
        ->and($defaultTimeline[0]['id'])->toBe($lastId - 49)
        ->and($defaultTimeline[49]['id'])->toBe($lastId)
        ->and($maxTimeline)->toHaveCount(100)
        ->and($maxTimeline[0]['id'])->toBe($firstId + 20)
        ->and($maxTimeline[99]['id'])->toBe($lastId);

    Http::assertNothingSent();
});

test('endpoint ejecuta una sola consulta de eventos sin N más 1', function () {
    [, $user, $invoice] = billingApiFixture();

    foreach (range(1, 5) as $attempt) {
        app(InvoicePacAuditService::class)->append($invoice, InvoicePacEventType::IssueAttempted, [
            'attempt' => $attempt,
        ]);
    }

    app(CurrentTenant::class)->clear();
    $eventSelects = 0;
    DB::listen(function ($query) use (&$eventSelects): void {
        if (str_contains(strtolower($query->sql), 'from "invoice_pac_events"')) {
            $eventSelects++;
        }
    });

    $this->actingAs($user, 'api')
        ->getJson("/api/invoices/{$invoice->id}/billing")
        ->assertOk()
        ->assertJsonCount(5, 'timeline');

    expect($eventSelects)->toBe(1);
    Http::assertNothingSent();
});
