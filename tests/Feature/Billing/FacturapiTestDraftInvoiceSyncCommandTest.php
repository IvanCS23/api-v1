<?php

use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Support\Facades\Http;

/**
 * billing:facturapi-test-draft-sync (Fase 6.2.4). Nunca crea nada — si
 * no existe pac_draft_external_id, falla localmente sin buscar/crear en
 * silencio.
 */
beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_DRAFT_SYNC_CMD',
    ]);

    if (method_exists(Http::class, 'preventStrayRequests')) {
        Http::preventStrayRequests();
    }
});

function draftedInvoiceForSyncCommandTest(): Invoice
{
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_draft_external_id' => 'inv_draft_sync_cmd_'.$invoice->id,
        'pac_draft_status' => 'draft',
        'pac_draft_ready_to_stamp' => false,
    ])->save();

    return $invoice->fresh();
}

test('prohibido en production', function () {
    $invoice = draftedInvoiceForSyncCommandTest();

    app()->instance('env', 'production');

    $this->artisan('billing:facturapi-test-draft-sync', ['invoice' => $invoice->id])
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('requiere FACTURAPI_TEST_KEY', function () {
    config(['services.facturapi.test_key' => null]);
    $invoice = draftedInvoiceForSyncCommandTest();

    $this->artisan('billing:facturapi-test-draft-sync', ['invoice' => $invoice->id])
        ->expectsOutputToContain('FACTURAPI_TEST_KEY no está configurada')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('sin pac_draft_external_id, falla localmente sin crear nada ni hacer HTTP', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);

    $this->artisan('billing:facturapi-test-draft-sync', ['invoice' => $invoice->id])
        ->expectsOutputToContain('nunca crea uno')
        ->assertExitCode(1);

    Http::assertNothingSent();
    expect($invoice->fresh()->pac_draft_external_id)->toBeNull();
});

test('requiere confirmación interactiva; si se rechaza, no hace HTTP', function () {
    $invoice = draftedInvoiceForSyncCommandTest();

    $this->artisan('billing:facturapi-test-draft-sync', ['invoice' => $invoice->id])
        ->expectsConfirmation('¿Continuar y consultar en Facturapi TEST el borrador de la Invoice ['.$invoice->id.']?', 'no')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

test('con confirmación aceptada, sincroniza y muestra el resumen sanitizado', function () {
    $invoice = draftedInvoiceForSyncCommandTest();

    Http::fake(['*' => Http::response([
        'id' => $invoice->pac_draft_external_id,
        'status' => 'draft',
        'livemode' => false,
        'is_ready_to_stamp' => true,
    ], 200)]);

    $this->artisan('billing:facturapi-test-draft-sync', ['invoice' => $invoice->id])
        ->expectsConfirmation('¿Continuar y consultar en Facturapi TEST el borrador de la Invoice ['.$invoice->id.']?', 'yes')
        ->expectsOutputToContain('Borrador TEST sincronizado correctamente')
        ->assertExitCode(0);

    expect($invoice->fresh()->pac_draft_ready_to_stamp)->toBeTrue();
});

test('invoice inexistente falla con un mensaje claro', function () {
    $this->artisan('billing:facturapi-test-draft-sync', ['invoice' => 999999])
        ->assertExitCode(1);

    Http::assertNothingSent();
});
