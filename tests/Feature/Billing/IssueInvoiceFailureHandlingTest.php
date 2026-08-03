<?php

use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Billing\IssueInvoiceService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Clasificación fallo DEFINITIVO (`failed`, reintentable con la misma
 * idempotency_key) vs. fallo AMBIGUO (`reconciliation_required`, nunca
 * reintentado automáticamente) — ver
 * IssueInvoiceService::isDefinitiveFailure(). También cubre el
 * saneamiento de `pac_last_error` y el caso "el PAC respondió con éxito
 * pero la persistencia local falló" (tratado como ambiguo, nunca como
 * fallo del PAC).
 */
beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_MUY_SECRETA_FAILURE_998877',
    ]);
});

function issuedInvoiceForFailureTest(): Invoice
{
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);

    app(CurrentTenant::class)->set($company->id);

    return $invoice->fresh(['items']);
}

test('error de validación (400/422) es DEFINITIVO: pac_issue_status=failed, reconciliation_required=false, reintentable', function (int $status) {
    $invoice = issuedInvoiceForFailureTest();

    Http::fake(['*' => Http::response(['message' => 'RFC inválido', 'code' => 'invalid_rfc'], $status)]);

    expect(fn () => app(IssueInvoiceService::class)->issue($invoice))
        ->toThrow(\App\Exceptions\Billing\PacValidationException::class);

    $fresh = $invoice->fresh();
    expect($fresh->pac_issue_status)->toBe('failed')
        ->and($fresh->pac_reconciliation_required)->toBeFalse()
        ->and($fresh->pac_last_error)->toContain('invalid_rfc')
        ->and($fresh->cfdi_uuid)->toBeNull();
})->with([400, 422]);

test('error de autenticación (401/403) es DEFINITIVO: pac_issue_status=failed', function (int $status) {
    $invoice = issuedInvoiceForFailureTest();

    Http::fake(['*' => Http::response(['message' => 'No autorizado', 'code' => 'unauthorized'], $status)]);

    expect(fn () => app(IssueInvoiceService::class)->issue($invoice))
        ->toThrow(\App\Exceptions\Billing\PacAuthenticationException::class);

    expect($invoice->fresh()->pac_issue_status)->toBe('failed');
})->with([401, 403]);

test('rate limit (429) es DEFINITIVO: pac_issue_status=failed', function () {
    $invoice = issuedInvoiceForFailureTest();

    Http::fake(['*' => Http::response(['message' => 'Demasiadas solicitudes', 'code' => 'rate_limited'], 429)]);

    expect(fn () => app(IssueInvoiceService::class)->issue($invoice))
        ->toThrow(\App\Exceptions\Billing\PacRateLimitException::class);

    expect($invoice->fresh()->pac_issue_status)->toBe('failed');
});

test('un snapshot fiscal incompleto es DEFINITIVO (no se intentó ninguna llamada HTTP): pac_issue_status=failed', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued, 'client_rfc' => '']);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);
    app(CurrentTenant::class)->set($company->id);

    Http::fake();

    expect(fn () => app(IssueInvoiceService::class)->issue($invoice))
        ->toThrow(\App\Exceptions\Billing\InvoiceFiscalSnapshotIncompleteException::class);

    Http::assertNothingSent();
    expect($invoice->fresh()->pac_issue_status)->toBe('failed');
});

test('5xx del PAC es AMBIGUO: pac_issue_status=reconciliation_required, pac_reconciliation_required=true', function () {
    $invoice = issuedInvoiceForFailureTest();

    Http::fake(['*' => Http::response(['message' => 'Error interno del PAC'], 500)]);

    expect(fn () => app(IssueInvoiceService::class)->issue($invoice))
        ->toThrow(\App\Exceptions\Billing\PacUnavailableException::class);

    $fresh = $invoice->fresh();
    expect($fresh->pac_issue_status)->toBe('reconciliation_required')
        ->and($fresh->pac_reconciliation_required)->toBeTrue();
});

test('un timeout/conexión interrumpida es AMBIGUO: pac_issue_status=reconciliation_required', function () {
    $invoice = issuedInvoiceForFailureTest();

    Http::fake(function () {
        throw new ConnectionException('Connection timed out después de 5 segundos');
    });

    expect(fn () => app(IssueInvoiceService::class)->issue($invoice))
        ->toThrow(ConnectionException::class);

    $fresh = $invoice->fresh();
    expect($fresh->pac_issue_status)->toBe('reconciliation_required')
        ->and($fresh->pac_reconciliation_required)->toBeTrue()
        ->and($fresh->pac_idempotency_key)->not->toBeNull();
});

test('una respuesta 200 no parseable/inesperada es AMBIGUA: pac_issue_status=reconciliation_required', function () {
    $invoice = issuedInvoiceForFailureTest();

    Http::fake(['*' => Http::response(['foo' => 'bar'], 200)]);

    expect(fn () => app(IssueInvoiceService::class)->issue($invoice))
        ->toThrow(\App\Exceptions\Billing\PacUnexpectedResponseException::class);

    expect($invoice->fresh()->pac_issue_status)->toBe('reconciliation_required');
});

test('pac_last_error nunca contiene la API key, Authorization, Bearer, ni el payload fiscal completo', function () {
    $invoice = issuedInvoiceForFailureTest();

    Http::fake(['*' => Http::response(['message' => 'RFC inválido para el cliente', 'code' => 'invalid_rfc'], 422)]);

    try {
        app(IssueInvoiceService::class)->issue($invoice);
    } catch (\App\Exceptions\Billing\PacValidationException) {
        // esperado
    }

    $error = $invoice->fresh()->pac_last_error;

    expect($error)->toContain('invalid_rfc')
        ->and($error)->not->toContain('sk_test_MUY_SECRETA_FAILURE_998877')
        ->and($error)->not->toContain('Bearer')
        ->and($error)->not->toContain('Authorization');
});

test('si el PAC responde con éxito pero la persistencia local falla (violación de índice único), se marca reconciliation_required (no failed) y no queda escritura parcial', function () {
    $company = Company::factory()->create();
    app(CurrentTenant::class)->set($company->id);

    $existing = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    $existing->forceFill(['pac_provider' => 'facturapi', 'pac_external_id' => 'inv_duplicado'])->save();

    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);

    // Mismo company_id + pac_provider + pac_external_id que $existing =>
    // viola erp_invoices_pac_provider_external_unique al guardar.
    Http::fake(['*' => Http::response([
        'id' => 'inv_duplicado',
        'status' => 'valid',
        'uuid' => 'BBBBBBBB-1111-2222-3333-444444444444',
    ], 200)]);

    expect(fn () => app(IssueInvoiceService::class)->issue($invoice->fresh(['items'])))
        ->toThrow(\Illuminate\Database\QueryException::class);

    $fresh = $invoice->fresh();
    // pac_provider SÍ queda fijado: se marca durante la RESERVA (que ya
    // hizo commit exitosamente antes de llamar al PAC), no durante esta
    // transacción de éxito que falló y revirtió — lo que se comprueba es
    // que NINGÚN campo propio de esta segunda transacción quedó escrito
    // a medias.
    expect($fresh->pac_provider)->toBe('facturapi')
        ->and($fresh->pac_external_id)->toBeNull()
        ->and($fresh->cfdi_uuid)->toBeNull()
        ->and($fresh->pac_status)->toBeNull()
        ->and($fresh->stamped_at)->toBeNull()
        ->and($fresh->last_pac_sync_at)->toBeNull()
        ->and($fresh->pac_issue_status)->toBe('reconciliation_required')
        ->and($fresh->pac_reconciliation_required)->toBeTrue()
        ->and($fresh->pac_last_error)->toContain('inv_duplicado');
});
