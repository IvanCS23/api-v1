<?php

use App\Enums\InvoiceStatus;
use App\Exceptions\Billing\PacValidationException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Billing\IssueInvoiceService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Support\Facades\Http;

/**
 * `pac_idempotency_key` (`erp-invoice:{company_id}:{invoice_id}:v1`) y
 * `external_id` (`company-{company_id}-invoice-{invoice_id}`) son
 * funciones puras de (company_id, invoice_id) — nunca aleatorias, nunca
 * dependen de pac_external_id (que no existe todavía al momento de
 * calcularlas). Ver IssueInvoiceService::idempotencyKeyFor()/
 * externalIdFor().
 */
beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_IDEMPOTENCY',
    ]);
});

function issuedInvoiceForIdempotencyTest(Company $company): Invoice
{
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);

    app(CurrentTenant::class)->set($company->id);

    return $invoice->fresh(['items']);
}

test('la reserva calcula pac_idempotency_key de forma determinista: erp-invoice:{company_id}:{invoice_id}:v1', function () {
    $company = Company::factory()->create();
    $invoice = issuedInvoiceForIdempotencyTest($company);

    Http::fake(['*' => Http::response(['id' => 'inv_1', 'status' => 'valid'], 200)]);

    $updated = app(IssueInvoiceService::class)->issue($invoice);

    expect($updated->pac_idempotency_key)->toBe("erp-invoice:{$company->id}:{$invoice->id}:v1");
});

test('external_id es determinista: company-{company_id}-invoice-{invoice_id}, y se envía en el payload al PAC', function () {
    $company = Company::factory()->create();
    $invoice = issuedInvoiceForIdempotencyTest($company);

    Http::fake(['*' => Http::response(['id' => 'inv_2', 'status' => 'valid'], 200)]);

    app(IssueInvoiceService::class)->issue($invoice);

    Http::assertSent(function ($request) use ($company, $invoice) {
        $body = $request->data();

        expect($body['external_id'])->toBe("company-{$company->id}-invoice-{$invoice->id}")
            ->and($body['idempotency_key'])->toBe("erp-invoice:{$company->id}:{$invoice->id}:v1");

        return true;
    });
});

test('compañías distintas nunca comparten idempotency_key, aunque el invoice_id coincida numéricamente', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $invoiceA = issuedInvoiceForIdempotencyTest($companyA);
    Http::fake(['*' => Http::response(['id' => 'inv_a', 'status' => 'valid'], 200)]);
    $updatedA = app(IssueInvoiceService::class)->issue($invoiceA);

    $invoiceB = issuedInvoiceForIdempotencyTest($companyB);
    Http::fake(['*' => Http::response(['id' => 'inv_b', 'status' => 'valid'], 200)]);
    $updatedB = app(IssueInvoiceService::class)->issue($invoiceB);

    expect($updatedA->pac_idempotency_key)->not->toBe($updatedB->pac_idempotency_key)
        ->and($updatedA->pac_idempotency_key)->toContain((string) $companyA->id)
        ->and($updatedB->pac_idempotency_key)->toContain((string) $companyB->id);
});

test('un retry tras un fallo definitivo reutiliza exactamente la misma idempotency_key, nunca genera una nueva', function () {
    $company = Company::factory()->create();
    $invoice = issuedInvoiceForIdempotencyTest($company);
    $expectedKey = "erp-invoice:{$company->id}:{$invoice->id}:v1";

    // Un segundo Http::fake() en la misma prueba NO reemplaza al primero
    // (Laravel acumula stubs en vez de sustituirlos) — se usa
    // fakeSequence() para servir la respuesta 422 en la primera llamada
    // y la 200 en la segunda, de forma determinista.
    Http::fakeSequence()
        ->push(['message' => 'RFC inválido', 'code' => 'invalid_rfc'], 422)
        ->push(['id' => 'inv_retry_success', 'status' => 'valid'], 200);

    // Primer intento: Facturapi rechaza el payload (422) -> failed, reintentable.
    try {
        app(IssueInvoiceService::class)->issue($invoice);
        test()->fail('Se esperaba PacValidationException en el primer intento');
    } catch (PacValidationException) {
        // esperado
    }

    $afterFirstAttempt = $invoice->fresh();
    expect($afterFirstAttempt->pac_issue_status)->toBe('failed')
        ->and($afterFirstAttempt->pac_idempotency_key)->toBe($expectedKey)
        ->and($afterFirstAttempt->pac_issue_attempts)->toBe(1);

    // Segundo intento: éxito. Debe reutilizar la MISMA llave.
    $updated = app(IssueInvoiceService::class)->issue($afterFirstAttempt);

    expect($updated->pac_idempotency_key)->toBe($expectedKey)
        ->and($updated->pac_issue_attempts)->toBe(2)
        ->and($updated->pac_issue_status)->toBe('succeeded');

    Http::assertSent(fn ($request) => ($request->data()['idempotency_key'] ?? null) === $expectedKey);
});

test('la reserva marca pending, fija pac_issue_started_at e incrementa pac_issue_attempts ANTES de llamar al PAC', function () {
    $company = Company::factory()->create();
    $invoice = issuedInvoiceForIdempotencyTest($company);

    $stateDuringCall = null;
    Http::fake(function () use (&$stateDuringCall, $invoice) {
        $stateDuringCall = \App\Models\Invoice::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)
            ->find($invoice->id);

        return Http::response(['id' => 'inv_pending_check', 'status' => 'valid'], 200);
    });

    app(IssueInvoiceService::class)->issue($invoice);

    expect($stateDuringCall->pac_issue_status)->toBe('pending')
        ->and($stateDuringCall->pac_issue_started_at)->not->toBeNull()
        ->and($stateDuringCall->pac_issue_attempts)->toBe(1)
        ->and($stateDuringCall->pac_idempotency_key)->not->toBeNull();
});
