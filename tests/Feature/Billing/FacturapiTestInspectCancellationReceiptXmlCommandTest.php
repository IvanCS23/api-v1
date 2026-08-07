<?php

use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_COMMAND_INSPECT_SECRET',
    ]);

    Http::preventStrayRequests();
    Storage::fake('local');
});

function invoiceForCancellationReceiptInspectCommand(array $overrides = []): Invoice
{
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Issued,
        'client_rfc' => 'RFCSECRETOCMDINSPECT',
    ]);

    $invoice->forceFill(array_merge([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_command_inspect_'.$invoice->id,
        'cfdi_uuid' => '96013e83-154b-4153-8e61-c38b8966e560',
        'pac_status' => 'canceled',
        'cancellation_status' => 'accepted',
        'cancellation_receipt_status' => 'reconciliation_required',
        'cancellation_receipt_last_error' => 'diagnostico anterior intacto',
    ], $overrides))->save();

    return $invoice->fresh();
}

function cancellationReceiptInspectConfirmation(Invoice $invoice): string
{
    return "Confirmas la inspeccion de solo lectura de la Invoice [{$invoice->id}]?";
}

test('default no cancela el diagnostico antes de HTTP', function () {
    $invoice = invoiceForCancellationReceiptInspectCommand();
    Http::fake();

    $this->artisan('billing:facturapi-test-cancellation-receipt-inspect', ['invoice' => $invoice->id])
        ->expectsOutputToContain('SOLO el XML')
        ->expectsOutputToContain('no guardara artifacts')
        ->expectsConfirmation(cancellationReceiptInspectConfirmation($invoice), 'no')
        ->expectsOutputToContain('No se realizo ninguna llamada HTTP')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

test('diagnostico imprime solo estructura y UUID enmascarados y deja Invoice intacta', function () {
    $invoice = invoiceForCancellationReceiptInspectCommand();
    $before = $invoice->getRawOriginal();
    $otherUuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    $xml = '<?xml version="1.0"?>'
        .'<Acuse xmlns="http://cancelacfd.sat.gob.mx" RequestUUID="'.$otherUuid.'">'
        .'<Folios><UUID>'.strtoupper((string) $invoice->cfdi_uuid).'</UUID></Folios>'
        .'<RfcEmisor>RFC-NUNCA-VISIBLE</RfcEmisor>'
        .'<Signature><X509Certificate>CERT-NUNCA-VISIBLE</X509Certificate></Signature>'
        .'</Acuse>';

    Http::fake([
        "*/invoices/{$invoice->pac_external_id}/cancellation_receipt/xml" => Http::response($xml, 200),
    ]);

    $this->artisan('billing:facturapi-test-cancellation-receipt-inspect', ['invoice' => $invoice->id])
        ->expectsConfirmation(cancellationReceiptInspectConfirmation($invoice), 'yes')
        ->expectsOutputToContain('root = Acuse')
        ->expectsOutputToContain('/Acuse/Folios/UUID')
        ->expectsOutputToContain('/Acuse/@RequestUUID')
        ->expectsOutputToContain('aaaaaaaa...eeee')
        ->expectsOutputToContain('96013e83...e560')
        ->expectsOutputToContain('matches_local')
        ->expectsOutputToContain('XML fue descartado sin persistirse')
        ->doesntExpectOutputToContain($otherUuid)
        ->doesntExpectOutputToContain((string) $invoice->cfdi_uuid)
        ->doesntExpectOutputToContain('RFC-NUNCA-VISIBLE')
        ->doesntExpectOutputToContain('CERT-NUNCA-VISIBLE')
        ->doesntExpectOutputToContain('sk_test_COMMAND_INSPECT_SECRET')
        ->doesntExpectOutputToContain('<Acuse')
        ->assertExitCode(0);

    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/cancellation_receipt/pdf'));
    expect($invoice->fresh()->getRawOriginal())->toBe($before)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('XML inseguro falla con mensaje generico sin filtrar su contenido', function () {
    $invoice = invoiceForCancellationReceiptInspectCommand();
    $secret = 'CONTENIDO-SECRETO-XXE';
    $xml = '<!DOCTYPE Acuse [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><Acuse>'.$secret.'&xxe;</Acuse>';
    Http::fake(['*' => Http::response($xml, 200)]);

    $this->artisan('billing:facturapi-test-cancellation-receipt-inspect', ['invoice' => $invoice->id])
        ->expectsConfirmation(cancellationReceiptInspectConfirmation($invoice), 'yes')
        ->expectsOutputToContain('No se pudo inspeccionar el XML')
        ->doesntExpectOutputToContain($secret)
        ->doesntExpectOutputToContain('file:///etc/passwd')
        ->assertExitCode(1);

    expect($invoice->fresh()->cancellation_receipt_status)->toBe('reconciliation_required')
        ->and($invoice->fresh()->cancellation_receipt_last_error)->toBe('diagnostico anterior intacto');
});

test('comando esta prohibido en production y exige llave TEST', function (string $case) {
    $invoice = invoiceForCancellationReceiptInspectCommand();
    Http::fake();

    if ($case === 'production') {
        app()->instance('env', 'production');
    } else {
        config(['services.facturapi.test_key' => null]);
    }

    $this->artisan('billing:facturapi-test-cancellation-receipt-inspect', ['invoice' => $invoice->id])
        ->assertExitCode(1);

    Http::assertNothingSent();
})->with(['production', 'missing key']);
