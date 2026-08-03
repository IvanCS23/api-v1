<?php

use App\Enums\InvoiceStatus;
use App\Exceptions\InvoiceCannotBeIssuedException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Billing\InvoiceWorkflow;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Support\Facades\Http;

/**
 * `InvoiceWorkflow::issueToPac()` (Fase 6.2, mecánica endurecida en Fase
 * 6.2.1) es el único punto de orquestación para el timbrado ante el PAC:
 * delega enteramente en IssueInvoiceService (toda la validación/reserva/
 * idempotencia/persistencia se prueba en detalle en
 * IssueInvoiceServiceTest y archivos relacionados). Estas pruebas solo
 * verifican la delegación en sí, no repiten cada caso ya cubierto allí.
 */
beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_WORKFLOW',
    ]);
});

test('issueToPac delega en IssueInvoiceService y retorna la Invoice con los campos PAC persistidos', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);
    app(CurrentTenant::class)->set($company->id);

    Http::fake(['*' => Http::response([
        'id' => 'inv_wf_1',
        'status' => 'valid',
        'uuid' => 'DDDDDDDD-1111-2222-3333-444444444444',
    ], 200)]);

    $updated = app(InvoiceWorkflow::class)->issueToPac($invoice);

    expect($updated->pac_external_id)->toBe('inv_wf_1')
        ->and($updated->cfdi_uuid)->toBe('DDDDDDDD-1111-2222-3333-444444444444')
        ->and($updated->pac_issue_status)->toBe('succeeded');
});

test('issueToPac propaga InvoiceCannotBeIssuedException para una factura que no está Issued', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Ready]);
    app(CurrentTenant::class)->set($company->id);

    Http::fake();

    expect(fn () => app(InvoiceWorkflow::class)->issueToPac($invoice))
        ->toThrow(InvoiceCannotBeIssuedException::class);

    Http::assertNothingSent();
});

test('issueToPac no interfiere con las transiciones existentes markReady/issue/cancel', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Draft]);

    $workflow = app(InvoiceWorkflow::class);

    $ready = $workflow->markReady($invoice);
    expect($ready->status)->toBe(InvoiceStatus::Ready);

    $issued = $workflow->issue($ready);
    expect($issued->status)->toBe(InvoiceStatus::Issued)
        ->and($issued->issued_at)->not->toBeNull();
});
