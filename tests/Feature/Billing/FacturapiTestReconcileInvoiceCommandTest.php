<?php

use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_RECONCILE_COMMAND_SECRET',
    ]);

    Http::preventStrayRequests();
});

function phase64CommandInvoice(): Invoice
{
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Issued,
        'folio' => 'FAC-CMD-64',
        'client_rfc' => 'SECRETORFC010101AAA',
        'client_calle' => 'Domicilio secreto 123',
    ]);
    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_external_id' => '6a7278ca2bb8f37d84b3876a',
        'cfdi_uuid' => 'fced601d-c4f6-4ce7-8f05-f3d38de530f9',
        'pac_status' => 'valid',
        'cancellation_status' => 'none',
        'pac_issue_status' => 'succeeded',
        'pac_reconciliation_required' => false,
        'cfdi_artifacts_status' => 'stored',
    ])->save();

    return $invoice->fresh();
}

test('comando de reconciliación está bloqueado en production', function () {
    $invoice = phase64CommandInvoice();
    app()->instance('env', 'production');
    Http::fake();

    $this->artisan('billing:facturapi-test-reconcile', ['invoice' => $invoice->id])
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('comando exige FACTURAPI_TEST_KEY', function () {
    $invoice = phase64CommandInvoice();
    config(['services.facturapi.test_key' => null]);
    Http::fake();

    $this->artisan('billing:facturapi-test-reconcile', ['invoice' => $invoice->id])
        ->expectsOutputToContain('FACTURAPI_TEST_KEY no está configurada')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('comando rechaza Invoice sin pac_external_id antes de cualquier HTTP', function () {
    $invoice = phase64CommandInvoice();
    $invoice->forceFill(['pac_external_id' => null])->save();
    Http::fake();

    $this->artisan('billing:facturapi-test-reconcile', ['invoice' => $invoice->id])
        ->expectsOutputToContain('no tiene pac_external_id')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('advertencia y confirmación negativa producen cero HTTP', function () {
    $invoice = phase64CommandInvoice();
    Http::fake();

    $this->artisan('billing:facturapi-test-reconcile', ['invoice' => $invoice->id])
        ->expectsOutputToContain('Esta operación CONSULTARÁ Facturapi TEST y reconciliará únicamente metadata fiscal local. No emitirá, timbrará ni cancelará CFDIs.')
        ->expectsConfirmation("¿Confirmas que quieres reconciliar la Invoice [{$invoice->id}] con Facturapi TEST?", 'no')
        ->expectsOutputToContain('Cancelado. No se realizó ninguna llamada HTTP.')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

test('salida exitosa está sanitizada y muestra ids abreviados', function () {
    $invoice = phase64CommandInvoice();
    Http::fake([
        "*/invoices/{$invoice->pac_external_id}" => Http::response([
            'id' => $invoice->pac_external_id,
            'livemode' => false,
            'status' => 'valid',
            'uuid' => strtoupper($invoice->cfdi_uuid),
            'cancellation_status' => 'none',
            'stamp' => ['date' => '2026-08-01T10:00:00Z'],
        ], 200),
    ]);

    $this->artisan('billing:facturapi-test-reconcile', ['invoice' => $invoice->id])
        ->expectsConfirmation("¿Confirmas que quieres reconciliar la Invoice [{$invoice->id}] con Facturapi TEST?", 'yes')
        ->expectsOutputToContain('Invoice reconciliada con Facturapi TEST')
        ->expectsOutputToContain('6a7278ca…876a')
        ->doesntExpectOutputToContain($invoice->pac_external_id)
        ->doesntExpectOutputToContain($invoice->cfdi_uuid)
        ->doesntExpectOutputToContain('SECRETORFC010101AAA')
        ->doesntExpectOutputToContain('Domicilio secreto')
        ->doesntExpectOutputToContain('sk_test_RECONCILE_COMMAND_SECRET')
        ->doesntExpectOutputToContain('Authorization')
        ->assertExitCode(0);

    Http::assertSentCount(1);
});
