<?php

use App\Enums\InvoiceHealthAction;
use App\Enums\InvoiceHealthStatus;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\Billing\InvoiceHealthEvaluator;
use App\Support\Billing\InvoiceHealthFinding;
use App\Support\Billing\PacErrorCode;
use App\Support\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/**
 * Fase 6.22: hallazgos de billing:health para un acuse que no se pudo
 * guardar. El bloqueo de identidad es informativo en modo test (Facturapi
 * TEST siempre entrega un acuse ajeno) y recuperable en live; el acuse no
 * disponible es informativo en ambos modos.
 */
beforeEach(function () {
    facturapiOrganizationsFromConfigKey();
    config(['services.facturapi.test_key' => 'sk_test_PHASE_622_HEALTH', 'billing.pac_mode' => 'test']);
    Http::preventStrayRequests();
});

const PHASE_622_MISMATCH = '[CANCELLATION_RECEIPT_UUID_MISMATCH] El UUID fiscal identificado en el acuse no corresponde al CFDI local para la factura [1].';

function phase622CanceledInvoice(array $overrides = []): Invoice
{
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Cancelled]);
    $invoice->forceFill(array_merge([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_622_health_'.$invoice->id,
        'cfdi_uuid' => 'fced601d-c4f6-4ce7-8f05-f3d38de530f9',
        'pac_status' => 'canceled',
        'pac_issue_status' => 'succeeded',
        'cancellation_status' => 'accepted',
        'pac_cancel_request_status' => 'completed',
        'cfdi_artifacts_status' => 'stored',
        'cfdi_xml_path' => 'cfdi/x.xml',
        'cfdi_pdf_path' => 'cfdi/x.pdf',
    ], $overrides))->save();

    return $invoice->fresh();
}

function phase622ReceiptFinding(Invoice $invoice): ?InvoiceHealthFinding
{
    app(CurrentTenant::class)->set($invoice->company_id);
    $report = app(InvoiceHealthEvaluator::class)->evaluate($invoice, CarbonImmutable::now());

    return $report->find('cancellation_receipt_identity_mismatch')
        ?? $report->find('cancellation_receipt_retry_pending');
}

test('PacErrorCode lee sólo el prefijo estable del error persistido', function (?string $error, ?string $expected) {
    expect(PacErrorCode::from($error))->toBe($expected);
})->with([
    'bloqueo de identidad' => [PHASE_622_MISMATCH, 'CANCELLATION_RECEIPT_UUID_MISMATCH'],
    'código PAC' => ['[invoice_cancellation_receipt_unavailable] no disponible', null],
    'sin prefijo' => ['Archivo local faltante', null],
    'null' => [null, null],
]);

test('el bloqueo de identidad del acuse depende del modo PAC', function (string $mode, InvoiceHealthStatus $status) {
    config(['billing.pac_mode' => $mode]);
    $finding = phase622ReceiptFinding(phase622CanceledInvoice([
        'cancellation_receipt_status' => 'reconciliation_required',
        'cancellation_receipt_last_error' => PHASE_622_MISMATCH,
    ]));

    expect($finding->code)->toBe('cancellation_receipt_identity_mismatch')
        ->and($finding->status)->toBe($status)
        ->and($finding->action)->toBe(InvoiceHealthAction::ManualReview);
})->with([
    'test: informativo (TEST siempre entrega un acuse ajeno)' => ['test', InvoiceHealthStatus::Valid],
    'live: recuperable' => ['live', InvoiceHealthStatus::Recoverable],
]);

test('un acuse no disponible es informativo en ambos modos', function (string $mode) {
    config(['billing.pac_mode' => $mode]);
    $finding = phase622ReceiptFinding(phase622CanceledInvoice([
        'cancellation_receipt_status' => 'reconciliation_required',
        'cancellation_receipt_last_error' => 'El acuse todavía no está disponible.',
    ]));

    expect($finding->code)->toBe('cancellation_receipt_retry_pending')
        ->and($finding->status)->toBe(InvoiceHealthStatus::Valid)
        ->and($finding->action)->toBe(InvoiceHealthAction::FetchReceipt);
})->with(['test', 'live']);

test('sin intento de acuse ni con acuse almacenado no hay hallazgo de acuse', function (?string $receiptStatus) {
    expect(phase622ReceiptFinding(phase622CanceledInvoice(['cancellation_receipt_status' => $receiptStatus])))->toBeNull();
})->with([null, 'stored']);

test('billing:health: el bloqueo no dispara --fail-on=recoverable en test y sí en live', function () {
    phase622CanceledInvoice([
        'cancellation_receipt_status' => 'reconciliation_required',
        'cancellation_receipt_last_error' => PHASE_622_MISMATCH,
    ]);
    app(CurrentTenant::class)->clear();

    config(['billing.pac_mode' => 'test']);
    expect(Artisan::call('billing:health', ['--fail-on' => 'recoverable']))->toBe(0);

    config(['billing.pac_mode' => 'live']);
    expect(Artisan::call('billing:health', ['--fail-on' => 'recoverable', '--format' => 'json']))->toBe(1);

    $output = Artisan::output();
    $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    expect($report['by_code'])->toBe(['cancellation_receipt_identity_mismatch' => 1])
        ->and($output)->not->toContain('El UUID fiscal identificado')
        ->not->toContain('fced601d');
    Http::assertNothingSent();
});
