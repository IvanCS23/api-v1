<?php

use App\Contracts\Billing\PacProvider;
use App\Data\Billing\PacInvoiceRequest;
use App\Enums\CfdiCancellationMotive;
use App\Enums\InvoiceStatus;
use App\Exceptions\Billing\PacUnavailableException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Billing\CancelInvoiceWithPacService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Cubre específicamente el comportamiento de reintento de
 * FacturapiProvider (ya implementado en Fase 6.1, `client()->retry(2,
 * 200, ...)`), no ejercitado todavía por FacturapiProviderResponseTest.
 * No modifica el adaptador — solo agrega la cobertura faltante.
 */
beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_RETRY',
    ]);
});

function fakeInvoiceForRetryTest(): Invoice
{
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);

    app(CurrentTenant::class)->set($company->id);

    return $invoice->fresh(['items']);
}

function requestForRetryTest(Invoice $invoice): PacInvoiceRequest
{
    return new PacInvoiceRequest(
        invoice: $invoice,
        idempotencyKey: "erp-invoice:{$invoice->company_id}:{$invoice->id}:v1",
        externalId: "company-{$invoice->company_id}-invoice-{$invoice->id}",
    );
}

test('createInvoice reintenta ante un fallo transitorio de conexión y tiene éxito en el segundo intento', function () {
    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        if ($attempts === 1) {
            throw new ConnectionException('Connection timed out después de 5 segundos');
        }

        return Http::response(['id' => 'inv_retry_ok', 'status' => 'valid'], 200);
    });

    $result = app(PacProvider::class)->createInvoice(requestForRetryTest(fakeInvoiceForRetryTest()));

    expect($attempts)->toBe(2)
        ->and($result->externalId)->toBe('inv_retry_ok');
});

test('createInvoice agota los reintentos y propaga ConnectionException si todos los intentos fallan por conexión', function () {
    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('Connection timed out después de 5 segundos');
    });

    expect(fn () => app(PacProvider::class)->createInvoice(requestForRetryTest(fakeInvoiceForRetryTest())))
        ->toThrow(ConnectionException::class);

    expect($attempts)->toBe(2);
});

test('createInvoice NO reintenta ante una respuesta HTTP de error ya recibida (5xx) — solo fallos de conexión son reintentables', function () {
    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        return Http::response(['message' => 'Error interno del PAC'], 500);
    });

    expect(fn () => app(PacProvider::class)->createInvoice(requestForRetryTest(fakeInvoiceForRetryTest())))
        ->toThrow(PacUnavailableException::class);

    expect($attempts)->toBe(1);
});

/*
 * Fase 6.19: política de reintentos por operación. Las consultas y las
 * escrituras con idempotency_key se reintentan una vez ante un fallo de
 * conexión; POST /stamp y DELETE (sin llave) nunca se reintentan.
 */
dataset('pac operations retry policy', [
    'GET retrieveInvoice' => ['retrieveInvoice', true],
    'GET findInvoiceByExternalId' => ['findInvoiceByExternalId', true],
    'GET retrieveDraftInvoice' => ['retrieveDraftInvoice', true],
    'GET downloadPdf' => ['downloadPdf', true],
    'GET downloadXml' => ['downloadXml', true],
    'GET downloadCancellationReceiptXml' => ['downloadCancellationReceiptXml', true],
    'GET downloadCancellationReceiptPdf' => ['downloadCancellationReceiptPdf', true],
    'POST createInvoice (con llave)' => ['createInvoice', true],
    'POST createDraftInvoice (con llave)' => ['createDraftInvoice', true],
    'PUT updateDraftInvoice (con llave)' => ['updateDraftInvoice', true],
    'POST /stamp (sin llave)' => ['stampDraftInvoice', false],
    'DELETE cancelación (sin llave)' => ['cancelInvoice', false],
]);

test('cada operación PAC respeta la política de reintentos ante ConnectionException', function (string $operation, bool $retried) {
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('cURL error 28: Operation timed out');
    });
    $request = requestForRetryTest(fakeInvoiceForRetryTest());
    $provider = app(PacProvider::class);

    $call = match ($operation) {
        'createInvoice', 'createDraftInvoice' => fn () => $provider->{$operation}($request),
        'updateDraftInvoice' => fn () => $provider->updateDraftInvoice('inv_retry', $request),
        'cancelInvoice' => fn () => $provider->cancelInvoice('inv_retry', '02', null),
        default => fn () => $provider->{$operation}('inv_retry'),
    };

    expect($call)->toThrow(ConnectionException::class);
    expect($attempts)->toBe($retried ? 2 : 1);
})->with('pac operations retry policy');

test('timeout del DELETE de cancelación: un solo envío y reconciliation_required', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_retry_cancel_'.$invoice->id,
        'cfdi_uuid' => 'fced601d-c4f6-4ce7-8f05-f3d38de530f9',
        'pac_status' => 'valid',
        'cancellation_status' => 'none',
        'pac_issue_status' => 'succeeded',
    ])->save();
    app(CurrentTenant::class)->set($company->id);
    $deletes = 0;
    Http::fake(function () use (&$deletes) {
        $deletes++;

        throw new ConnectionException('cURL error 28: Operation timed out');
    });

    expect(fn () => app(CancelInvoiceWithPacService::class)->cancel($invoice->fresh(), CfdiCancellationMotive::ErrorsWithoutRelation))
        ->toThrow(ConnectionException::class);

    $fresh = $invoice->fresh();
    expect($deletes)->toBe(1)
        ->and($fresh->pac_reconciliation_required)->toBeTrue()
        ->and($fresh->pac_status)->toBe('valid')
        ->and($fresh->cancellation_status)->toBe('none');
});
