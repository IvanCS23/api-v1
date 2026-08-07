<?php

use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_CANCEL_COMMAND_SECRET',
    ]);

    Http::preventStrayRequests();
});

function phase65CommandInvoice(): Invoice
{
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Issued,
        'folio' => 'FAC-CANCEL-CMD',
        'client_rfc' => 'COMMANDCANCELSECRET',
        'client_calle' => 'Domicilio secreto cancel command',
    ]);
    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_cancel_command_'.$invoice->id,
        'cfdi_uuid' => 'fced601d-c4f6-4ce7-8f05-f3d38de530f9',
        'pac_status' => 'valid',
        'cancellation_status' => 'none',
        'pac_reconciliation_required' => false,
        'cfdi_artifacts_status' => 'stored',
    ])->save();

    return $invoice->fresh();
}

test('comando cancel está bloqueado en production', function () {
    $invoice = phase65CommandInvoice();
    app()->instance('env', 'production');
    Http::fake();

    $this->artisan('billing:facturapi-test-cancel', [
        'invoice' => $invoice->id,
        '--motive' => '02',
    ])->assertExitCode(1);

    Http::assertNothingSent();
});

test('comando cancel exige FACTURAPI_TEST_KEY', function () {
    $invoice = phase65CommandInvoice();
    config(['services.facturapi.test_key' => null]);
    Http::fake();

    $this->artisan('billing:facturapi-test-cancel', [
        'invoice' => $invoice->id,
        '--motive' => '02',
    ])->expectsOutputToContain('FACTURAPI_TEST_KEY no está configurada')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('motivo es requerido y valores inválidos se rechazan sin HTTP', function (?string $motive) {
    $invoice = phase65CommandInvoice();
    Http::fake();
    $arguments = ['invoice' => $invoice->id];

    if ($motive !== null) {
        $arguments['--motive'] = $motive;
    }

    $this->artisan('billing:facturapi-test-cancel', $arguments)
        ->expectsOutputToContain('--motive es obligatoria')
        ->assertExitCode(1);

    Http::assertNothingSent();
})->with(['ausente' => [null], 'inválido' => ['99']]);

test('advertencia clara y confirmación negativa producen cero HTTP', function () {
    $invoice = phase65CommandInvoice();
    Http::fake();

    $this->artisan('billing:facturapi-test-cancel', [
        'invoice' => $invoice->id,
        '--motive' => '03',
    ])->expectsOutputToContain('SOLICITARÁ la cancelación fiscal')
        ->expectsOutputToContain('pending/verifying')
        ->expectsOutputToContain('no elimina la Invoice ni sus XML/PDF')
        ->expectsConfirmation("¿Confirmas que quieres SOLICITAR la cancelación fiscal de la Invoice [{$invoice->id}] en Facturapi TEST?", 'no')
        ->expectsOutputToContain('Cancelado. No se realizó ninguna llamada HTTP.')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

test('motivo 01 sin UUID sustituto falla antes del HTTP después de confirmar', function () {
    $invoice = phase65CommandInvoice();
    Http::fake();

    $this->artisan('billing:facturapi-test-cancel', [
        'invoice' => $invoice->id,
        '--motive' => '01',
    ])->expectsConfirmation("¿Confirmas que quieres SOLICITAR la cancelación fiscal de la Invoice [{$invoice->id}] en Facturapi TEST?", 'yes')
        ->expectsOutputToContain('No se pudo solicitar la cancelación fiscal')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('salida exitosa está sanitizada y no altera Invoice status', function () {
    $invoice = phase65CommandInvoice();
    Http::fake([
        "*/invoices/{$invoice->pac_external_id}*" => Http::response([
            'id' => $invoice->pac_external_id,
            'livemode' => false,
            'status' => 'valid',
            'cancellation_status' => 'pending',
            'uuid' => strtoupper($invoice->cfdi_uuid),
        ], 200),
    ]);

    $this->artisan('billing:facturapi-test-cancel', [
        'invoice' => $invoice->id,
        '--motive' => '02',
    ])->expectsConfirmation("¿Confirmas que quieres SOLICITAR la cancelación fiscal de la Invoice [{$invoice->id}] en Facturapi TEST?", 'yes')
        ->expectsOutputToContain('Solicitud de cancelación procesada')
        ->doesntExpectOutputToContain($invoice->cfdi_uuid)
        ->doesntExpectOutputToContain($invoice->pac_external_id)
        ->doesntExpectOutputToContain('COMMANDCANCELSECRET')
        ->doesntExpectOutputToContain('Domicilio secreto')
        ->doesntExpectOutputToContain('sk_test_CANCEL_COMMAND_SECRET')
        ->doesntExpectOutputToContain('Authorization')
        ->assertExitCode(0);

    $fresh = $invoice->fresh();
    expect($fresh->status)->toBe(InvoiceStatus::Issued)
        ->and($fresh->cancellation_status)->toBe('pending')
        ->and($fresh->pac_reconciliation_required)->toBeTrue();
    Http::assertSentCount(1);
});
