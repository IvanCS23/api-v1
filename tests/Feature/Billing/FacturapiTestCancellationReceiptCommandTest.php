<?php

use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_CANCELLATION_RECEIPT_COMMAND_SECRET',
    ]);

    Http::preventStrayRequests();
    Storage::fake('local');
});

function invoiceForCancellationReceiptCommand(array $overrides = []): Invoice
{
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Issued,
        'client_rfc' => 'RFCSECRETOCMD01',
        'client_calle' => 'Domicilio secreto del comando 661',
    ]);

    $invoice->forceFill(array_merge([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_receipt_command_'.$invoice->id,
        'cfdi_uuid' => '96013e83-154b-4153-8e61-c38b8966e560',
        'pac_status' => 'canceled',
        'cancellation_status' => 'accepted',
    ], $overrides))->save();

    return $invoice->fresh();
}

function fakeCancellationReceiptCommandHttp(Invoice $invoice): void
{
    Http::fake([
        "*/invoices/{$invoice->pac_external_id}/cancellation_receipt/xml" => Http::response(
            '<?xml version="1.0"?><Acuse xmlns="http://cancelacfd.sat.gob.mx"><Folios><UUID>'.$invoice->cfdi_uuid.'</UUID><EstatusUUID>201</EstatusUUID></Folios></Acuse>',
            200,
        ),
        "*/invoices/{$invoice->pac_external_id}/cancellation_receipt/pdf" => Http::response("%PDF-1.7\nacuse command\n%%EOF", 200),
    ]);
}

function cancellationReceiptConfirmation(Invoice $invoice): string
{
    return "Confirmas que quieres descargar el acuse de cancelacion de la Invoice [{$invoice->id}] desde Facturapi TEST?";
}

test('comando de acuse esta prohibido en production', function () {
    $invoice = invoiceForCancellationReceiptCommand();
    app()->instance('env', 'production');
    Http::fake();

    $this->artisan('billing:facturapi-test-cancellation-receipt', ['invoice' => $invoice->id])
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('comando de acuse exige FACTURAPI_TEST_KEY', function () {
    config(['services.facturapi.test_key' => null]);
    $invoice = invoiceForCancellationReceiptCommand();
    Http::fake();

    $this->artisan('billing:facturapi-test-cancellation-receipt', ['invoice' => $invoice->id])
        ->expectsOutputToContain('FACTURAPI_TEST_KEY no esta configurada')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('Invoice inexistente falla sin HTTP', function () {
    Http::fake();

    $this->artisan('billing:facturapi-test-cancellation-receipt', ['invoice' => 999999])
        ->expectsOutputToContain('No existe ninguna Invoice')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('Invoice no canceled falla antes de confirmacion y sin HTTP', function () {
    $invoice = invoiceForCancellationReceiptCommand(['pac_status' => 'valid']);
    Http::fake();

    $this->artisan('billing:facturapi-test-cancellation-receipt', ['invoice' => $invoice->id])
        ->expectsOutputToContain('pac_status=canceled')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('Invoice no accepted falla y pending explica que sigue en curso', function () {
    $invoice = invoiceForCancellationReceiptCommand(['cancellation_status' => 'pending']);
    Http::fake();

    $this->artisan('billing:facturapi-test-cancellation-receipt', ['invoice' => $invoice->id])
        ->expectsOutputToContain('sigue en curso')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('migracion 6.6 pendiente se diagnostica antes de confirmar y sin HTTP', function () {
    $invoice = invoiceForCancellationReceiptCommand();
    Http::fake();
    Schema::partialMock()
        ->shouldReceive('hasColumns')
        ->once()
        ->andReturnFalse();

    $this->artisan('billing:facturapi-test-cancellation-receipt', ['invoice' => $invoice->id])
        ->expectsOutputToContain('La migración de FASE 6.6 no está aplicada')
        ->expectsOutputToContain('php artisan migrate')
        ->assertExitCode(1);

    Http::assertNothingSent();
    expect($invoice->fresh()->cancellation_receipt_status)->toBeNull();
});

test('muestra advertencia requerida antes de confirmar y default no', function () {
    $invoice = invoiceForCancellationReceiptCommand();
    Http::fake();

    $this->artisan('billing:facturapi-test-cancellation-receipt', ['invoice' => $invoice->id])
        ->expectsOutputToContain('DESCARGARÁ el acuse XML/PDF de cancelación desde Facturapi TEST')
        ->expectsOutputToContain('No cancelará nuevamente el CFDI')
        ->expectsConfirmation(cancellationReceiptConfirmation($invoice), 'no')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

test('confirmacion no deja Invoice intacta y hace cero HTTP', function () {
    $invoice = invoiceForCancellationReceiptCommand();
    Http::fake();

    $this->artisan('billing:facturapi-test-cancellation-receipt', ['invoice' => $invoice->id])
        ->expectsConfirmation(cancellationReceiptConfirmation($invoice), 'no')
        ->expectsOutputToContain('Cancelado. No se realizo ninguna llamada HTTP.')
        ->assertExitCode(0);

    Http::assertNothingSent();
    expect($invoice->fresh()->cancellation_receipt_status)->toBeNull();
});

test('confirmacion si descarga y muestra resumen permitido', function () {
    $invoice = invoiceForCancellationReceiptCommand();
    fakeCancellationReceiptCommandHttp($invoice);

    $this->artisan('billing:facturapi-test-cancellation-receipt', ['invoice' => $invoice->id])
        ->expectsConfirmation(cancellationReceiptConfirmation($invoice), 'yes')
        ->expectsOutputToContain('Acuse de cancelacion obtenido correctamente')
        ->expectsOutputToContain('XML guardado')
        ->assertExitCode(0);

    $fresh = $invoice->fresh();
    expect($fresh->cancellation_receipt_status)->toBe('stored')
        ->and($fresh->cancellation_receipt_xml_path)->not->toBeNull()
        ->and($fresh->cancellation_receipt_pdf_path)->not->toBeNull();
    Http::assertSentCount(2);
});

test('salida esta sanitizada y UUID siempre enmascarado', function () {
    $invoice = invoiceForCancellationReceiptCommand();
    fakeCancellationReceiptCommandHttp($invoice);

    $this->artisan('billing:facturapi-test-cancellation-receipt', ['invoice' => $invoice->id])
        ->expectsConfirmation(cancellationReceiptConfirmation($invoice), 'yes')
        ->expectsOutputToContain('96013e83...e560')
        ->doesntExpectOutputToContain($invoice->cfdi_uuid)
        ->doesntExpectOutputToContain('RFCSECRETOCMD01')
        ->doesntExpectOutputToContain('Domicilio secreto')
        ->doesntExpectOutputToContain('sk_test_CANCELLATION_RECEIPT_COMMAND_SECRET')
        ->doesntExpectOutputToContain('Authorization')
        ->doesntExpectOutputToContain('Bearer')
        ->doesntExpectOutputToContain('<Acuse')
        ->doesntExpectOutputToContain('%PDF-')
        ->doesntExpectOutputToContain('storage/app')
        ->doesntExpectOutputToContain('cancellation-receipts/')
        ->assertExitCode(0);
});

test('segunda ejecucion del comando es idempotente y no repite HTTP', function () {
    $invoice = invoiceForCancellationReceiptCommand();
    fakeCancellationReceiptCommandHttp($invoice);

    $this->artisan('billing:facturapi-test-cancellation-receipt', ['invoice' => $invoice->id])
        ->expectsConfirmation(cancellationReceiptConfirmation($invoice), 'yes')
        ->assertExitCode(0);
    Http::assertSentCount(2);

    $this->artisan('billing:facturapi-test-cancellation-receipt', ['invoice' => $invoice->id])
        ->expectsConfirmation(cancellationReceiptConfirmation($invoice), 'yes')
        ->expectsOutputToContain('Acuse de cancelacion obtenido correctamente')
        ->assertExitCode(0);

    Http::assertSentCount(2);
});

test('provider falla despues de iniciar y el comando nunca deja diagnostico null', function (
    int $httpStatus,
    string $pacCode,
    string $expectedStatus,
    string $expectedOutput,
) {
    Log::spy();
    $invoice = invoiceForCancellationReceiptCommand();
    Http::fake([
        "*/invoices/{$invoice->pac_external_id}/cancellation_receipt/xml" => Http::response([
            'message' => 'Mensaje PAC sanitizable',
            'code' => $pacCode,
        ], $httpStatus),
    ]);

    $this->artisan('billing:facturapi-test-cancellation-receipt', ['invoice' => $invoice->id])
        ->expectsConfirmation(cancellationReceiptConfirmation($invoice), 'yes')
        ->expectsOutputToContain($expectedOutput)
        ->expectsOutputToContain('Código PAC seguro: '.$pacCode)
        ->assertExitCode(1);

    $fresh = $invoice->fresh();
    expect($fresh->cancellation_receipt_status)->toBe($expectedStatus)
        ->and($fresh->cancellation_receipt_status)->not->toBeNull()
        ->and($fresh->cancellation_receipt_last_error)->not->toBeNull()
        ->and($fresh->cancellation_receipt_last_error)->toContain($pacCode);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $event, array $context): bool => $event === 'billing.invoice.cancellation_receipt_attempt'
            && $context['invoice_id'] === $invoice->id
            && $context['company_id'] === $invoice->company_id
            && $context['cancellation_receipt_status'] === $expectedStatus
            && $context['pac_error_code'] === $pacCode)
        ->once();
})->with([
    'CancellationReceiptUnavailableException' => [
        400,
        'invoice_cancellation_receipt_unavailable',
        'reconciliation_required',
        'ACUSE NO DISPONIBLE AÚN',
    ],
    'PacValidationException' => [400, 'invalid_request', 'failed', 'ERROR DE DESCARGA / VALIDACIÓN'],
    'PacAuthenticationException' => [401, 'unauthorized', 'failed', 'ERROR DE DESCARGA / VALIDACIÓN'],
    'PacRateLimitException' => [429, 'rate_limit', 'failed', 'ERROR DE DESCARGA / VALIDACIÓN'],
    'PacUnavailableException' => [500, 'internal_error', 'reconciliation_required', 'ERROR DE DESCARGA / VALIDACIÓN'],
    'PacUnexpectedResponseException' => [418, 'unexpected_response', 'reconciliation_required', 'ERROR DE DESCARGA / VALIDACIÓN'],
]);

test('errores locales XML y PDF capturados por el comando dejan DB y log trazables', function (string $failure) {
    Log::spy();
    $invoice = invoiceForCancellationReceiptCommand();

    if ($failure === 'xml') {
        Http::fake([
            "*/invoices/{$invoice->pac_external_id}/cancellation_receipt/xml" => Http::response('<html>no es un Acuse</html>', 200),
        ]);
    } else {
        Http::fake([
            "*/invoices/{$invoice->pac_external_id}/cancellation_receipt/xml" => Http::response(
                '<?xml version="1.0"?><Acuse><Folios><UUID>'.$invoice->cfdi_uuid.'</UUID></Folios></Acuse>',
                200,
            ),
            "*/invoices/{$invoice->pac_external_id}/cancellation_receipt/pdf" => Http::response('{"error":"not pdf"}', 200),
        ]);
    }

    $this->artisan('billing:facturapi-test-cancellation-receipt', ['invoice' => $invoice->id])
        ->expectsConfirmation(cancellationReceiptConfirmation($invoice), 'yes')
        ->expectsOutputToContain('ERROR DE DESCARGA / VALIDACIÓN')
        ->assertExitCode(1);

    $fresh = $invoice->fresh();
    expect($fresh->cancellation_receipt_status)->toBe('reconciliation_required')
        ->and($fresh->cancellation_receipt_last_error)->not->toBeNull();

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $event, array $context): bool => $event === 'billing.invoice.cancellation_receipt_attempt'
            && $context['invoice_id'] === $invoice->id
            && $context['cancellation_receipt_status'] === 'reconciliation_required'
            && $context['pac_error_code'] === null)
        ->once();
})->with(['xml', 'pdf']);

test('error Storage capturado por el comando deja DB y log trazables', function () {
    Log::spy();
    $invoice = invoiceForCancellationReceiptCommand();
    fakeCancellationReceiptCommandHttp($invoice);

    $disk = Mockery::mock();
    $disk->shouldReceive('put')->once()->andReturnFalse();
    Storage::shouldReceive('disk')->with('local')->andReturn($disk);

    $this->artisan('billing:facturapi-test-cancellation-receipt', ['invoice' => $invoice->id])
        ->expectsConfirmation(cancellationReceiptConfirmation($invoice), 'yes')
        ->expectsOutputToContain('ERROR DE DESCARGA / VALIDACIÓN')
        ->assertExitCode(1);

    $fresh = $invoice->fresh();
    expect($fresh->cancellation_receipt_status)->toBe('reconciliation_required')
        ->and($fresh->cancellation_receipt_last_error)->not->toBeNull();

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $event, array $context): bool => $event === 'billing.invoice.cancellation_receipt_attempt'
            && $context['invoice_id'] === $invoice->id
            && $context['cancellation_receipt_status'] === 'reconciliation_required')
        ->once();
});
