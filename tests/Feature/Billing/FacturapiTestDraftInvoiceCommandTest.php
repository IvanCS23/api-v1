<?php

use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Support\Facades\Http;

/**
 * billing:facturapi-test-draft (Fase 6.2.4). Nunca timbra. Nunca usa
 * IssueInvoiceService. Cero HTTP real en tests (Http::fake() +
 * Http::preventStrayRequests()).
 */
beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_MUY_SECRETA_DRAFT_CMD_7788',
    ]);

    if (method_exists(Http::class, 'preventStrayRequests')) {
        Http::preventStrayRequests();
    }
});

function readyInvoiceForDraftCommandTest(): Invoice
{
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Issued,
        'client_rfc' => 'COMANDODRAFTSECRETO01AAA',
        'client_calle' => 'Calle Confidencial Del Draft 789',
    ]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);

    return $invoice->fresh(['items']);
}

test('prohibido en production', function () {
    $invoice = readyInvoiceForDraftCommandTest();

    app()->instance('env', 'production');

    $this->artisan('billing:facturapi-test-draft', ['invoice' => $invoice->id])
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('requiere FACTURAPI_TEST_KEY, con mensaje sanitizado', function () {
    config(['services.facturapi.test_key' => null]);
    $invoice = readyInvoiceForDraftCommandTest();

    $this->artisan('billing:facturapi-test-draft', ['invoice' => $invoice->id])
        ->expectsOutputToContain('FACTURAPI_TEST_KEY no está configurada')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('requiere confirmación interactiva; si se rechaza, no hace nada', function () {
    $invoice = readyInvoiceForDraftCommandTest();

    $this->artisan('billing:facturapi-test-draft', ['invoice' => $invoice->id])
        ->expectsConfirmation('¿Continuar y crear/sincronizar el borrador TEST para la Invoice ['.$invoice->id.']?', 'no')
        ->assertExitCode(0);

    Http::assertNothingSent();
    expect($invoice->fresh()->pac_draft_external_id)->toBeNull();
});

test('advierte explícitamente que creará un recurso real antes de pedir confirmación', function () {
    $invoice = readyInvoiceForDraftCommandTest();

    $this->artisan('billing:facturapi-test-draft', ['invoice' => $invoice->id])
        ->expectsOutputToContain('CREARÁ un recurso draft real en Facturapi TEST')
        ->expectsConfirmation('¿Continuar y crear/sincronizar el borrador TEST para la Invoice ['.$invoice->id.']?', 'no')
        ->assertExitCode(0);
});

test('con confirmación aceptada, crea el borrador y muestra el resumen sanitizado', function () {
    $invoice = readyInvoiceForDraftCommandTest();

    Http::fake(['*' => Http::response([
        'id' => 'inv_draft_cmd_'.$invoice->id,
        'status' => 'draft',
        'livemode' => false,
        'is_ready_to_stamp' => true,
        'created_at' => '2026-08-07T10:00:00Z',
    ], 200)]);

    $this->artisan('billing:facturapi-test-draft', ['invoice' => $invoice->id])
        ->expectsConfirmation('¿Continuar y crear/sincronizar el borrador TEST para la Invoice ['.$invoice->id.']?', 'yes')
        ->expectsOutputToContain('Borrador TEST creado/sincronizado correctamente')
        ->assertExitCode(0);

    expect($invoice->fresh()->pac_draft_external_id)->toBe('inv_draft_cmd_'.$invoice->id)
        ->and($invoice->fresh()->cfdi_uuid)->toBeNull()
        ->and($invoice->fresh()->pac_issue_status)->toBeNull();
});

test('nunca timbra ni usa IssueInvoiceService: no persiste pac_external_id/cfdi_uuid/pac_issue_status', function () {
    $invoice = readyInvoiceForDraftCommandTest();

    Http::fake(['*' => Http::response([
        'id' => 'inv_draft_no_stamp',
        'status' => 'draft',
        'livemode' => false,
        'is_ready_to_stamp' => true,
    ], 200)]);

    $this->artisan('billing:facturapi-test-draft', ['invoice' => $invoice->id])
        ->expectsConfirmation('¿Continuar y crear/sincronizar el borrador TEST para la Invoice ['.$invoice->id.']?', 'yes')
        ->assertExitCode(0);

    // Solo se envió un POST (crear el draft), nunca uno de timbrado.
    Http::assertSentCount(1);

    $fresh = $invoice->fresh();
    expect($fresh->pac_external_id)->toBeNull()
        ->and($fresh->cfdi_uuid)->toBeNull()
        ->and($fresh->pac_issue_status)->toBeNull();
});

test('la salida nunca contiene la API key, el RFC, ni el domicilio real de la Invoice', function () {
    $invoice = readyInvoiceForDraftCommandTest();

    Http::fake(['*' => Http::response([
        'id' => 'inv_draft_sanitized',
        'status' => 'draft',
        'livemode' => false,
        'is_ready_to_stamp' => true,
    ], 200)]);

    $this->artisan('billing:facturapi-test-draft', ['invoice' => $invoice->id])
        ->expectsConfirmation('¿Continuar y crear/sincronizar el borrador TEST para la Invoice ['.$invoice->id.']?', 'yes')
        ->doesntExpectOutputToContain('COMANDODRAFTSECRETO01AAA')
        ->doesntExpectOutputToContain('Calle Confidencial Del Draft')
        ->doesntExpectOutputToContain('sk_test_MUY_SECRETA_DRAFT_CMD_7788')
        ->assertExitCode(0);
});

test('invoice inexistente falla con un mensaje claro', function () {
    $this->artisan('billing:facturapi-test-draft', ['invoice' => 999999])
        ->assertExitCode(1);

    Http::assertNothingSent();
});
