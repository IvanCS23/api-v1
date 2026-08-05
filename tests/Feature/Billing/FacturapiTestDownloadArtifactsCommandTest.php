<?php

use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * billing:facturapi-test-artifacts (Fase 6.3). Nunca muestra el XML/PDF
 * completos, RFC, domicilio, API key, Authorization ni rutas del
 * servidor. Cero HTTP real en tests (Http::fake() + Storage::fake()).
 */
beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_MUY_SECRETA_ARTIFACTS_CMD_9911',
    ]);

    Storage::fake('local');

    if (method_exists(Http::class, 'preventStrayRequests')) {
        Http::preventStrayRequests();
    }
});

function stampedInvoiceForArtifactsCommandTest(): Invoice
{
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Issued,
        'client_rfc' => 'COMANDOARTIFACTSSECRETO1',
        'client_calle' => 'Calle Confidencial De Artifacts 999',
    ]);
    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_stamped_cmd_'.$invoice->id,
        'cfdi_uuid' => 'fced601d-c4f6-4ce7-8f05-f3d38de530f9',
        'pac_status' => 'valid',
        'pac_issue_status' => 'succeeded',
        'stamped_at' => now(),
    ])->save();

    return $invoice->fresh();
}

function fakeArtifactsHttpForCommand(Invoice $invoice): void
{
    Http::fake([
        "*/invoices/{$invoice->pac_external_id}/xml" => Http::response(
            '<?xml version="1.0"?><cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4"><cfdi:Complemento><tfd:TimbreFiscalDigital xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" UUID="'.$invoice->cfdi_uuid.'"/></cfdi:Complemento></cfdi:Comprobante>',
            200,
        ),
        "*/invoices/{$invoice->pac_external_id}/pdf" => Http::response("%PDF-1.4\nfake pdf\n%%EOF", 200),
    ]);
}

test('prohibido en production', function () {
    $invoice = stampedInvoiceForArtifactsCommandTest();

    app()->instance('env', 'production');

    $this->artisan('billing:facturapi-test-artifacts', ['invoice' => $invoice->id])
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('requiere FACTURAPI_TEST_KEY', function () {
    config(['services.facturapi.test_key' => null]);
    $invoice = stampedInvoiceForArtifactsCommandTest();

    $this->artisan('billing:facturapi-test-artifacts', ['invoice' => $invoice->id])
        ->expectsOutputToContain('FACTURAPI_TEST_KEY no está configurada')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('invoice inexistente falla con un mensaje claro', function () {
    $this->artisan('billing:facturapi-test-artifacts', ['invoice' => 999999])
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('Invoice sin pac_status=valid (aún draft/pendiente) es rechazada sin HTTP', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);

    $this->artisan('billing:facturapi-test-artifacts', ['invoice' => $invoice->id])
        ->expectsOutputToContain('no es un CFDI timbrado válido')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('advierte explícitamente antes de pedir confirmación', function () {
    $invoice = stampedInvoiceForArtifactsCommandTest();

    $this->artisan('billing:facturapi-test-artifacts', ['invoice' => $invoice->id])
        ->expectsOutputToContain('DESCARGARÁ el XML y el PDF reales')
        ->expectsConfirmation('¿Confirmas que quieres descargar los artifacts de la Invoice ['.$invoice->id.'] desde Facturapi TEST?', 'no')
        ->assertExitCode(0);
});

test('confirmación rechazada no hace HTTP ni modifica la Invoice', function () {
    $invoice = stampedInvoiceForArtifactsCommandTest();

    $this->artisan('billing:facturapi-test-artifacts', ['invoice' => $invoice->id])
        ->expectsConfirmation('¿Confirmas que quieres descargar los artifacts de la Invoice ['.$invoice->id.'] desde Facturapi TEST?', 'no')
        ->expectsOutputToContain('Cancelado. No se realizó ninguna llamada HTTP.')
        ->assertExitCode(0);

    Http::assertNothingSent();
    expect($invoice->fresh()->cfdi_artifacts_status)->toBeNull();
});

test('con confirmación aceptada, descarga y muestra el resumen sanitizado', function () {
    $invoice = stampedInvoiceForArtifactsCommandTest();

    fakeArtifactsHttpForCommand($invoice);

    $this->artisan('billing:facturapi-test-artifacts', ['invoice' => $invoice->id])
        ->expectsConfirmation('¿Confirmas que quieres descargar los artifacts de la Invoice ['.$invoice->id.'] desde Facturapi TEST?', 'yes')
        ->expectsOutputToContain('Artifacts obtenidos correctamente')
        ->assertExitCode(0);

    $fresh = $invoice->fresh();
    expect($fresh->cfdi_artifacts_status)->toBe('stored')
        ->and($fresh->cfdi_xml_path)->not->toBeNull()
        ->and($fresh->cfdi_pdf_path)->not->toBeNull();
});

test('la salida nunca contiene el XML/PDF completos, la API key, el RFC, el domicilio, Authorization ni rutas del servidor', function () {
    $invoice = stampedInvoiceForArtifactsCommandTest();

    fakeArtifactsHttpForCommand($invoice);

    $this->artisan('billing:facturapi-test-artifacts', ['invoice' => $invoice->id])
        ->expectsConfirmation('¿Confirmas que quieres descargar los artifacts de la Invoice ['.$invoice->id.'] desde Facturapi TEST?', 'yes')
        ->doesntExpectOutputToContain('COMANDOARTIFACTSSECRETO1')
        ->doesntExpectOutputToContain('Calle Confidencial De Artifacts')
        ->doesntExpectOutputToContain('sk_test_MUY_SECRETA_ARTIFACTS_CMD_9911')
        ->doesntExpectOutputToContain('Bearer')
        ->doesntExpectOutputToContain('Authorization')
        ->doesntExpectOutputToContain('TimbreFiscalDigital')
        ->doesntExpectOutputToContain('%PDF-')
        ->doesntExpectOutputToContain('storage/app')
        ->doesntExpectOutputToContain($invoice->cfdi_uuid) // UUID completo nunca, solo enmascarado
        ->assertExitCode(0);
});

test('el UUID mostrado está enmascarado, nunca completo', function () {
    $invoice = stampedInvoiceForArtifactsCommandTest();

    fakeArtifactsHttpForCommand($invoice);

    $this->artisan('billing:facturapi-test-artifacts', ['invoice' => $invoice->id])
        ->expectsConfirmation('¿Confirmas que quieres descargar los artifacts de la Invoice ['.$invoice->id.'] desde Facturapi TEST?', 'yes')
        ->expectsOutputToContain('fced601d…30f9')
        ->assertExitCode(0);
});

test('segunda ejecución (artifacts ya obtenidos) no repite HTTP', function () {
    $invoice = stampedInvoiceForArtifactsCommandTest();

    fakeArtifactsHttpForCommand($invoice);

    $this->artisan('billing:facturapi-test-artifacts', ['invoice' => $invoice->id])
        ->expectsConfirmation('¿Confirmas que quieres descargar los artifacts de la Invoice ['.$invoice->id.'] desde Facturapi TEST?', 'yes')
        ->assertExitCode(0);

    Http::assertSentCount(2);

    $this->artisan('billing:facturapi-test-artifacts', ['invoice' => $invoice->id])
        ->expectsConfirmation('¿Confirmas que quieres descargar los artifacts de la Invoice ['.$invoice->id.'] desde Facturapi TEST?', 'yes')
        ->expectsOutputToContain('Artifacts obtenidos correctamente')
        ->assertExitCode(0);

    Http::assertSentCount(2); // sin cambio: la segunda ejecución no volvió a llamar al PAC
});
