<?php

use App\Enums\InvoiceHealthAction;
use App\Enums\InvoiceHealthStatus;
use App\Enums\InvoiceStatus;
use App\Exceptions\Billing\PacUnavailableException;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\Billing\DownloadCancellationReceiptService;
use App\Services\Billing\InvoiceHealthEvaluator;
use App\Support\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Fase 6.22: una descarga de acuse reservada (`pending`) que nunca
 * terminó (proceso caído a mitad del HTTP) ya no bloquea para siempre.
 * Mismo patrón que cfdi_artifacts_started_at (Fase 6.21).
 */
beforeEach(function () {
    facturapiOrganizationsFromConfigKey();
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_PHASE_622_RECEIPT',
        'billing.cancellation_receipt.pending_minutes' => 10,
    ]);

    Storage::fake('local');
    Http::preventStrayRequests();
});

function phase622ReceiptInvoice(array $overrides = []): Invoice
{
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Cancelled]);
    $invoice->forceFill(array_merge([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_622_receipt_'.$invoice->id,
        'cfdi_uuid' => '96013e83-154b-4153-8e61-c38b8966e560',
        'pac_status' => 'canceled',
        'pac_issue_status' => 'succeeded',
        'cancellation_status' => 'accepted',
        'pac_cancel_request_status' => 'completed',
        'cfdi_artifacts_status' => 'stored',
        'cfdi_xml_path' => 'cfdi/x.xml',
        'cfdi_pdf_path' => 'cfdi/x.pdf',
    ], $overrides))->save();
    app(CurrentTenant::class)->set($company->id);

    return $invoice->fresh();
}

function phase622FakeReceipt(Invoice $invoice): void
{
    Http::fake([
        "*/invoices/{$invoice->pac_external_id}/cancellation_receipt/xml" => Http::response(
            '<?xml version="1.0" encoding="UTF-8"?><Acuse Fecha="2026-10-21T10:00:00" RfcEmisor="AAA010101AAA">'
            .'<Folios><UUID>'.strtoupper((string) $invoice->cfdi_uuid).'</UUID><EstatusUUID>201</EstatusUUID></Folios></Acuse>',
        ),
        "*/invoices/{$invoice->pac_external_id}/cancellation_receipt/pdf" => Http::response("%PDF-1.4\nacuse\n%%EOF"),
    ]);
}

test('la migración agrega cancellation_receipt_started_at nullable', function () {
    expect(Schema::hasColumn('invoices', 'cancellation_receipt_started_at'))->toBeTrue();
});

test('la reserva fija cancellation_receipt_started_at antes del HTTP', function () {
    $invoice = phase622ReceiptInvoice();
    $startedAtDuringHttp = null;

    Http::fake(function () use ($invoice, &$startedAtDuringHttp) {
        $startedAtDuringHttp ??= Invoice::query()->whereKey($invoice->id)->value('cancellation_receipt_started_at');

        return Http::response('', 500);
    });

    expect(fn () => app(DownloadCancellationReceiptService::class)->download($invoice))->toThrow(PacUnavailableException::class);
    expect($startedAtDuringHttp)->not->toBeNull();
});

test('una reserva pending vencida o sin fecha puede retomarse; una vigente no', function (?int $minutesAgo, bool $resumes) {
    $invoice = phase622ReceiptInvoice([
        'cancellation_receipt_status' => 'pending',
        'cancellation_receipt_started_at' => $minutesAgo === null ? null : now()->subMinutes($minutesAgo),
    ]);
    phase622FakeReceipt($invoice);

    if ($resumes) {
        app(DownloadCancellationReceiptService::class)->download($invoice);

        expect($invoice->fresh()->cancellation_receipt_status)->toBe('stored');
    } else {
        expect(fn () => app(DownloadCancellationReceiptService::class)->download($invoice))
            ->toThrow(RuntimeException::class, 'descarga de acuse en curso');

        Http::assertNothingSent();
    }
})->with([
    'vigente (2 min)' => [2, false],
    'vencida (30 min)' => [30, true],
    'sin fecha (anterior a la columna)' => [null, true],
]);

test('billing:health distingue una descarga de acuse en curso de una abandonada', function (int $minutesAgo, string $code, InvoiceHealthStatus $status, InvoiceHealthAction $action) {
    $invoice = phase622ReceiptInvoice([
        'cancellation_receipt_status' => 'pending',
        'cancellation_receipt_started_at' => now()->subMinutes($minutesAgo),
    ]);

    $finding = app(InvoiceHealthEvaluator::class)->evaluate($invoice, CarbonImmutable::now())->find($code);

    expect($finding)->not->toBeNull()
        ->and($finding->status)->toBe($status)
        ->and($finding->action)->toBe($action)
        ->and($finding->ageMinutes)->toBe($minutesAgo);
})->with([
    'en curso' => [3, 'cancellation_receipt_download_pending', InvoiceHealthStatus::Transient, InvoiceHealthAction::Wait],
    'abandonada' => [25, 'cancellation_receipt_download_pending_stale', InvoiceHealthStatus::Recoverable, InvoiceHealthAction::FetchReceipt],
]);
