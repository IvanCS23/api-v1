<?php

use App\Contracts\Billing\PacProvider;
use App\Data\Billing\PacInvoiceDraftResult;
use App\Data\Billing\PacInvoiceRequest;
use App\Exceptions\Billing\PacAuthenticationException;
use App\Exceptions\Billing\PacUnexpectedEnvironmentException;
use App\Exceptions\Billing\PacUnexpectedResponseException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Support\Facades\Http;

/**
 * CONTRACT + PAYLOAD + RESULT (Fase 6.2.4): createDraftInvoice()/
 * retrieveDraftInvoice() sobre FacturapiProvider. `POST /v2/invoices`
 * con `status: "draft"` — el mecanismo real de prevalidación de
 * Facturapi (no existe dry_run). Todas las pruebas usan Http::fake();
 * Http::preventStrayRequests() cuando está disponible.
 */
beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_DRAFT_CONTRACT',
    ]);

    if (method_exists(Http::class, 'preventStrayRequests')) {
        Http::preventStrayRequests();
    }
});

function invoiceForDraftTest(array $overrides = []): Invoice
{
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(array_merge(['company_id' => $company->id], $overrides));
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);

    app(CurrentTenant::class)->set($company->id);

    return $invoice->fresh(['items']);
}

function draftRequestFor(Invoice $invoice, string $externalId = 'company-1-invoice-1-draft'): PacInvoiceRequest
{
    return new PacInvoiceRequest(
        invoice: $invoice,
        idempotencyKey: "erp-invoice-draft:{$invoice->company_id}:{$invoice->id}:v1",
        externalId: $externalId,
    );
}

function fakeDraftResponseBody(array $overrides = []): array
{
    return array_merge([
        'id' => 'inv_draft_123',
        'status' => 'draft',
        'livemode' => false,
        'is_ready_to_stamp' => true,
        'created_at' => '2026-08-07T10:00:00Z',
        'external_id' => 'company-1-invoice-1-draft',
        'idempotency_key' => 'erp-invoice-draft:1:1:v1',
    ], $overrides);
}

// ==================== CONTRACT ====================

test('createDraftInvoice y retrieveDraftInvoice existen en el contrato PacProvider', function () {
    expect(method_exists(PacProvider::class, 'createDraftInvoice'))->toBeTrue()
        ->and(method_exists(PacProvider::class, 'retrieveDraftInvoice'))->toBeTrue();
});

test('createDraftInvoice llama POST /invoices (mismo endpoint que createInvoice, distinguido por status=draft)', function () {
    Http::fake(['*' => Http::response(fakeDraftResponseBody(), 200)]);

    app(PacProvider::class)->createDraftInvoice(draftRequestFor(invoiceForDraftTest()));

    Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/invoices'));
});

test('retrieveDraftInvoice llama GET /invoices/{id}', function () {
    Http::fake(['*' => Http::response(fakeDraftResponseBody(), 200)]);

    app(PacProvider::class)->retrieveDraftInvoice('inv_draft_123');

    Http::assertSent(fn ($request) => $request->method() === 'GET' && str_ends_with($request->url(), '/invoices/inv_draft_123'));
});

test('el DTO devuelto es PacInvoiceDraftResult, nunca PacInvoiceResult', function () {
    Http::fake(['*' => Http::response(fakeDraftResponseBody(), 200)]);

    $result = app(PacProvider::class)->createDraftInvoice(draftRequestFor(invoiceForDraftTest()));

    expect($result)->toBeInstanceOf(PacInvoiceDraftResult::class);
});

// ==================== PAYLOAD ====================

test('el payload incluye status=draft, customer, items, payment_form, currency, external_id e idempotency_key de draft, sin totals ni address artificial', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'payment_form' => '03',
        'payment_method' => null,
        'currency' => 'MXN',
    ]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);
    app(CurrentTenant::class)->set($company->id);

    Http::fake(['*' => Http::response(fakeDraftResponseBody(), 200)]);

    $request = draftRequestFor($invoice->fresh(['items']), "company-{$company->id}-invoice-{$invoice->id}-draft");
    app(PacProvider::class)->createDraftInvoice($request);

    Http::assertSent(function ($request) use ($company, $invoice) {
        $body = $request->data();

        expect($body['status'])->toBe('draft')
            ->and($body['customer']['legal_name'])->toBe($invoice->client_name)
            ->and($body['items'])->toHaveCount(1)
            ->and($body['payment_form'])->toBe('03')
            ->and($body['currency'])->toBe('MXN')
            ->and($body['external_id'])->toBe("company-{$company->id}-invoice-{$invoice->id}-draft")
            ->and($body['idempotency_key'])->toBe("erp-invoice-draft:{$company->id}:{$invoice->id}:v1")
            ->and($body)->not->toHaveKey('totals')
            ->and($body)->not->toHaveKey('address')
            ->and($body)->not->toHaveKey('payment_method');

        return true;
    });
});

test('payment_method solo se incluye cuando el snapshot lo trae', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'payment_method' => 'PPD']);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);
    app(CurrentTenant::class)->set($company->id);

    Http::fake(['*' => Http::response(fakeDraftResponseBody(), 200)]);

    app(PacProvider::class)->createDraftInvoice(draftRequestFor($invoice->fresh(['items'])));

    Http::assertSent(fn ($request) => ($request->data()['payment_method'] ?? null) === 'PPD');
});

// ==================== RESULT ====================

test('id, status draft, livemode false e is_ready_to_stamp se mapean correctamente', function () {
    Http::fake(['*' => Http::response(fakeDraftResponseBody(['is_ready_to_stamp' => true]), 200)]);

    $result = app(PacProvider::class)->createDraftInvoice(draftRequestFor(invoiceForDraftTest()));

    expect($result->externalId)->toBe('inv_draft_123')
        ->and($result->status)->toBe('draft')
        ->and($result->livemode)->toBeFalse()
        ->and($result->isReadyToStamp)->toBeTrue()
        ->and($result->createdAt)->toBeInstanceOf(\Carbon\CarbonImmutable::class)
        ->and($result->externalReference)->toBe('company-1-invoice-1-draft')
        ->and($result->idempotencyKey)->toBe('erp-invoice-draft:1:1:v1')
        ->and($result->rawResponse['id'])->toBe('inv_draft_123');
});

test('is_ready_to_stamp = false se mapea correctamente (no es un error)', function () {
    Http::fake(['*' => Http::response(fakeDraftResponseBody(['is_ready_to_stamp' => false]), 200)]);

    $result = app(PacProvider::class)->createDraftInvoice(draftRequestFor(invoiceForDraftTest()));

    expect($result->isReadyToStamp)->toBeFalse();
});

test('una respuesta inesperada (sin id) produce PacUnexpectedResponseException', function () {
    Http::fake(['*' => Http::response(['status' => 'draft', 'livemode' => false, 'is_ready_to_stamp' => true], 200)]);

    expect(fn () => app(PacProvider::class)->createDraftInvoice(draftRequestFor(invoiceForDraftTest())))
        ->toThrow(PacUnexpectedResponseException::class);
});

test('status distinto de draft (ej. "valid") NUNCA se interpreta como draft correcto — PacUnexpectedResponseException', function () {
    Http::fake(['*' => Http::response(fakeDraftResponseBody(['status' => 'valid']), 200)]);

    expect(fn () => app(PacProvider::class)->createDraftInvoice(draftRequestFor(invoiceForDraftTest())))
        ->toThrow(PacUnexpectedResponseException::class);
});

test('ausencia de is_ready_to_stamp produce PacUnexpectedResponseException', function () {
    $body = fakeDraftResponseBody();
    unset($body['is_ready_to_stamp']);
    Http::fake(['*' => Http::response($body, 200)]);

    expect(fn () => app(PacProvider::class)->createDraftInvoice(draftRequestFor(invoiceForDraftTest())))
        ->toThrow(PacUnexpectedResponseException::class);
});

test('livemode=true bloquea con PacUnexpectedEnvironmentException, nunca se interpreta como borrador TEST', function () {
    Http::fake(['*' => Http::response(fakeDraftResponseBody(['livemode' => true]), 200)]);

    try {
        app(PacProvider::class)->createDraftInvoice(draftRequestFor(invoiceForDraftTest()));
        test()->fail('Se esperaba PacUnexpectedEnvironmentException');
    } catch (PacUnexpectedEnvironmentException $e) {
        expect($e->remoteId)->toBe('inv_draft_123')
            ->and($e->context)->toBe('createDraftInvoice');
    }
});

test('livemode=true también bloquea en retrieveDraftInvoice', function () {
    Http::fake(['*' => Http::response(fakeDraftResponseBody(['livemode' => true]), 200)]);

    expect(fn () => app(PacProvider::class)->retrieveDraftInvoice('inv_draft_123'))
        ->toThrow(PacUnexpectedEnvironmentException::class);
});

test('ausencia del campo livemode produce PacUnexpectedResponseException (nunca se asume false)', function () {
    $body = fakeDraftResponseBody();
    unset($body['livemode']);
    Http::fake(['*' => Http::response($body, 200)]);

    expect(fn () => app(PacProvider::class)->createDraftInvoice(draftRequestFor(invoiceForDraftTest())))
        ->toThrow(PacUnexpectedResponseException::class);
});

test('errores HTTP (401) se mapean igual que en createInvoice', function () {
    Http::fake(['*' => Http::response(['message' => 'No autorizado', 'code' => 'unauthorized'], 401)]);

    expect(fn () => app(PacProvider::class)->createDraftInvoice(draftRequestFor(invoiceForDraftTest())))
        ->toThrow(PacAuthenticationException::class);
});

test('la API key nunca aparece en el mensaje de una excepción lanzada desde createDraftInvoice', function () {
    config(['services.facturapi.test_key' => 'sk_test_MUY_SECRETA_DRAFT_99887']);
    Http::fake(['*' => Http::response(['message' => 'No autorizado'], 401)]);

    try {
        app(PacProvider::class)->createDraftInvoice(draftRequestFor(invoiceForDraftTest()));
        test()->fail('Se esperaba PacAuthenticationException');
    } catch (PacAuthenticationException $e) {
        expect($e->getMessage())->not->toContain('sk_test_MUY_SECRETA_DRAFT_99887')
            ->and((string) $e)->not->toContain('sk_test_MUY_SECRETA_DRAFT_99887');
    }
});
