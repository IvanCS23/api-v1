<?php

use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Support\Facades\Http;

/**
 * billing:facturapi-test-stamp (Fase 6.2.5) — el comando de mayor
 * consecuencia del proyecto: TIMBRA de verdad un borrador ya existente en
 * Facturapi TEST. Nunca usa createInvoice(). Cero HTTP real en tests
 * (Http::fake() + Http::preventStrayRequests()).
 */
beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_MUY_SECRETA_STAMP_CMD_5544',
    ]);

    if (method_exists(Http::class, 'preventStrayRequests')) {
        Http::preventStrayRequests();
    }
});

function draftReadyInvoiceForStampCommandTest(): Invoice
{
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Issued,
        'client_rfc' => 'COMANDOSTAMPSECRETO01AAA',
        'client_calle' => 'Calle Confidencial Del Stamp 456',
    ]);
    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_draft_external_id' => 'inv_draft_stamp_cmd_'.$invoice->id,
        'pac_draft_idempotency_key' => "erp-invoice-draft:{$company->id}:{$invoice->id}:v1",
        'pac_draft_status' => 'draft',
        'pac_draft_ready_to_stamp' => true,
    ])->save();

    return $invoice->fresh();
}

function fakeSyncedReadyDraftBodyForCommand(Invoice $invoice, array $overrides = []): array
{
    return array_merge([
        'id' => $invoice->pac_draft_external_id,
        'status' => 'draft',
        'livemode' => false,
        'is_ready_to_stamp' => true,
    ], $overrides);
}

function fakeStampSuccessBodyForCommand(Invoice $invoice, array $overrides = []): array
{
    return array_merge([
        'id' => $invoice->pac_draft_external_id,
        'status' => 'valid',
        'livemode' => false,
        'uuid' => 'BBBBBBBB-1111-2222-3333-444444444444',
        'stamp' => ['date' => '2026-08-07T12:00:00Z'],
    ], $overrides);
}

test('prohibido en production', function () {
    $invoice = draftReadyInvoiceForStampCommandTest();

    app()->instance('env', 'production');

    $this->artisan('billing:facturapi-test-stamp', ['invoice' => $invoice->id])
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('requiere FACTURAPI_TEST_KEY, con mensaje sanitizado', function () {
    config(['services.facturapi.test_key' => null]);
    $invoice = draftReadyInvoiceForStampCommandTest();

    $this->artisan('billing:facturapi-test-stamp', ['invoice' => $invoice->id])
        ->expectsOutputToContain('FACTURAPI_TEST_KEY no está configurada')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('invoice inexistente falla con un mensaje claro', function () {
    $this->artisan('billing:facturapi-test-stamp', ['invoice' => 999999])
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('sin pac_draft_external_id, falla localmente sin hacer ninguna llamada HTTP', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);

    $this->artisan('billing:facturapi-test-stamp', ['invoice' => $invoice->id])
        ->expectsOutputToContain('no tiene un borrador remoto registrado')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('advierte explícitamente que TIMBRARÁ un recurso real antes de pedir confirmación', function () {
    $invoice = draftReadyInvoiceForStampCommandTest();

    $this->artisan('billing:facturapi-test-stamp', ['invoice' => $invoice->id])
        ->expectsOutputToContain('TIMBRARÁ el borrador en Facturapi TEST')
        ->expectsOutputToContain('El recurso dejará de ser un draft')
        ->expectsConfirmation('¿Confirmas que quieres TIMBRAR el borrador de la Invoice ['.$invoice->id.'] en Facturapi TEST?', 'no')
        ->assertExitCode(0);
});

test('requiere confirmación interactiva; si se rechaza, no hace ninguna llamada HTTP ni modifica la Invoice', function () {
    $invoice = draftReadyInvoiceForStampCommandTest();

    $this->artisan('billing:facturapi-test-stamp', ['invoice' => $invoice->id])
        ->expectsConfirmation('¿Confirmas que quieres TIMBRAR el borrador de la Invoice ['.$invoice->id.'] en Facturapi TEST?', 'no')
        ->expectsOutputToContain('Cancelado. No se realizó ninguna llamada HTTP.')
        ->assertExitCode(0);

    Http::assertNothingSent();

    $fresh = $invoice->fresh();
    expect($fresh->cfdi_uuid)->toBeNull()
        ->and($fresh->pac_external_id)->toBeNull()
        ->and($fresh->pac_issue_status)->toBeNull();
});

test('con confirmación aceptada, timbra el borrador y muestra el resumen sanitizado', function () {
    $invoice = draftReadyInvoiceForStampCommandTest();

    Http::fake([
        "*/invoices/{$invoice->pac_draft_external_id}" => Http::response(fakeSyncedReadyDraftBodyForCommand($invoice), 200),
        "*/invoices/{$invoice->pac_draft_external_id}/stamp" => Http::response(fakeStampSuccessBodyForCommand($invoice), 200),
    ]);

    $this->artisan('billing:facturapi-test-stamp', ['invoice' => $invoice->id])
        ->expectsConfirmation('¿Confirmas que quieres TIMBRAR el borrador de la Invoice ['.$invoice->id.'] en Facturapi TEST?', 'yes')
        ->expectsOutputToContain('Borrador timbrado correctamente')
        ->assertExitCode(0);

    $fresh = $invoice->fresh();
    expect($fresh->cfdi_uuid)->toBe('BBBBBBBB-1111-2222-3333-444444444444')
        ->and($fresh->pac_external_id)->toBe($invoice->pac_draft_external_id)
        ->and($fresh->pac_status)->toBe('valid')
        ->and($fresh->pac_issue_status)->toBe('succeeded');
});

test('draft aún no listo tras sincronizar (is_ready_to_stamp=false): el comando falla legiblemente, nunca llama a /stamp', function () {
    $invoice = draftReadyInvoiceForStampCommandTest();

    Http::fake(['*' => Http::response(fakeSyncedReadyDraftBodyForCommand($invoice, ['is_ready_to_stamp' => false]), 200)]);

    $this->artisan('billing:facturapi-test-stamp', ['invoice' => $invoice->id])
        ->expectsConfirmation('¿Confirmas que quieres TIMBRAR el borrador de la Invoice ['.$invoice->id.'] en Facturapi TEST?', 'yes')
        ->expectsOutputToContain('No se pudo timbrar el borrador')
        ->assertExitCode(1);

    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/stamp'));

    $fresh = $invoice->fresh();
    expect($fresh->cfdi_uuid)->toBeNull()
        ->and($fresh->pac_external_id)->toBeNull();
});

test('la salida nunca contiene la API key, el RFC, el domicilio, el UUID completo, ni Authorization', function () {
    $invoice = draftReadyInvoiceForStampCommandTest();

    Http::fake([
        "*/invoices/{$invoice->pac_draft_external_id}" => Http::response(fakeSyncedReadyDraftBodyForCommand($invoice), 200),
        "*/invoices/{$invoice->pac_draft_external_id}/stamp" => Http::response(fakeStampSuccessBodyForCommand($invoice), 200),
    ]);

    $this->artisan('billing:facturapi-test-stamp', ['invoice' => $invoice->id])
        ->expectsConfirmation('¿Confirmas que quieres TIMBRAR el borrador de la Invoice ['.$invoice->id.'] en Facturapi TEST?', 'yes')
        ->doesntExpectOutputToContain('COMANDOSTAMPSECRETO01AAA')
        ->doesntExpectOutputToContain('Calle Confidencial Del Stamp')
        ->doesntExpectOutputToContain('sk_test_MUY_SECRETA_STAMP_CMD_5544')
        ->doesntExpectOutputToContain('BBBBBBBB-1111-2222-3333-444444444444')
        ->doesntExpectOutputToContain('Bearer')
        ->doesntExpectOutputToContain('Authorization')
        ->assertExitCode(0);
});

test('nunca crea un CFDI nuevo: solo se envían un GET (sync) y un POST .../stamp', function () {
    $invoice = draftReadyInvoiceForStampCommandTest();

    Http::fake([
        "*/invoices/{$invoice->pac_draft_external_id}" => Http::response(fakeSyncedReadyDraftBodyForCommand($invoice), 200),
        "*/invoices/{$invoice->pac_draft_external_id}/stamp" => Http::response(fakeStampSuccessBodyForCommand($invoice), 200),
    ]);

    $this->artisan('billing:facturapi-test-stamp', ['invoice' => $invoice->id])
        ->expectsConfirmation('¿Confirmas que quieres TIMBRAR el borrador de la Invoice ['.$invoice->id.'] en Facturapi TEST?', 'yes')
        ->assertExitCode(0);

    Http::assertSentCount(2);
    Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/invoices'));
});
