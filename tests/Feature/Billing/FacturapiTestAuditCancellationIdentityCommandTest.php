<?php

use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_IDENTITY_COMMAND_SECRET',
    ]);

    Http::preventStrayRequests();
    Storage::fake('local');
});

function invoiceForCancellationIdentityAuditCommand(string $localUuid): Invoice
{
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Issued,
        'client_rfc' => 'RFCSECRETOIDENTITYCOMMAND',
    ]);
    $xml = '<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4">'
        .'<cfdi:Complemento><tfd:TimbreFiscalDigital xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" UUID="'.$localUuid.'"/></cfdi:Complemento>'
        .'</cfdi:Comprobante>';
    $path = 'cfdi-command/'.$invoice->id.'.xml';
    Storage::disk('local')->put($path, $xml);

    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_external_id' => '6a7645c39bb354793ba2ab2f',
        'cfdi_uuid' => $localUuid,
        'pac_status' => 'canceled',
        'cancellation_status' => 'accepted',
        'pac_response' => ['status' => 'canceled', 'uuid' => $localUuid],
        'pac_draft_response' => ['status' => 'valid', 'uuid' => $localUuid],
        'cfdi_xml_path' => $path,
        'cfdi_xml_sha256' => hash('sha256', $xml),
        'cfdi_artifacts_status' => 'stored',
        'cancellation_receipt_status' => 'reconciliation_required',
    ])->save();

    return $invoice->fresh();
}

function fakeCancellationIdentityAuditCommandHttp(Invoice $invoice, string $remoteUuid, string $receiptUuid): void
{
    Http::fake(function ($request) use ($invoice, $remoteUuid, $receiptUuid) {
        if (str_ends_with($request->url(), '/cancellation_receipt/xml')) {
            return Http::response('<Acuse><Folios><UUID>'.$receiptUuid.'</UUID></Folios></Acuse>', 200);
        }

        return Http::response([
            'id' => $invoice->pac_external_id,
            'status' => 'canceled',
            'cancellation_status' => 'accepted',
            'uuid' => $remoteUuid,
            'livemode' => false,
            'stamp' => ['date' => '2026-08-01T12:34:56.000Z'],
            'customer' => ['tax_id' => 'RFC-REMOTO-SECRETO'],
        ], 200);
    });
}

function cancellationIdentityAuditConfirmation(Invoice $invoice): string
{
    return "Confirmas la auditoria de identidad de la Invoice [{$invoice->id}]?";
}

test('comando tiene default no y hace cero HTTP', function () {
    $invoice = invoiceForCancellationIdentityAuditCommand('96013e83-154b-4153-8e61-c38b8966e560');
    Http::fake();

    $this->artisan('billing:facturapi-test-cancellation-identity-audit', ['invoice' => $invoice->id])
        ->expectsOutputToContain('SOLO LECTURA')
        ->expectsOutputToContain('no modifica DB')
        ->expectsConfirmation(cancellationIdentityAuditConfirmation($invoice), 'no')
        ->expectsOutputToContain('No se realizo ninguna llamada HTTP')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

test('comando muestra correlacion sanitizada y no modifica Invoice', function () {
    $local = '96013e83-154b-4153-8e61-c38b8966e560';
    $receipt = 'cf5138a2-1111-2222-3333-444444442e90';
    $invoice = invoiceForCancellationIdentityAuditCommand($local);
    $before = $invoice->getRawOriginal();
    fakeCancellationIdentityAuditCommandHttp($invoice, strtoupper($local), strtoupper($receipt));

    $this->artisan('billing:facturapi-test-cancellation-identity-audit', ['invoice' => $invoice->id])
        ->expectsConfirmation(cancellationIdentityAuditConfirmation($invoice), 'yes')
        ->expectsOutputToContain('A local DB')
        ->expectsOutputToContain('B retrieve remoto')
        ->expectsOutputToContain('C Acuse/Folios/UUID')
        ->expectsOutputToContain('D TimbreFiscalDigital/@UUID')
        ->expectsOutputToContain('96013e83...e560')
        ->expectsOutputToContain('CF5138A2...2E90')
        ->expectsOutputToContain('local_equals_remote')
        ->expectsOutputToContain('local_equals_receipt')
        ->expectsOutputToContain('remote_equals_receipt')
        ->expectsOutputToContain('scenario_1_receipt_does_not_belong_to_expected_cfdi')
        ->expectsOutputToContain('Auditoria terminada sin persistencia')
        ->doesntExpectOutputToContain($local)
        ->doesntExpectOutputToContain($receipt)
        ->doesntExpectOutputToContain('RFCSECRETOIDENTITY01')
        ->doesntExpectOutputToContain('RFC-REMOTO-SECRETO')
        ->doesntExpectOutputToContain('sk_test_IDENTITY_COMMAND_SECRET')
        ->doesntExpectOutputToContain('<Acuse')
        ->assertExitCode(0);

    expect($invoice->fresh()->getRawOriginal())->toBe($before);
    Http::assertSentCount(2);
    Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/cancellation_receipt/pdf'));
});

test('comando se bloquea en production antes de HTTP', function () {
    $invoice = invoiceForCancellationIdentityAuditCommand('96013e83-154b-4153-8e61-c38b8966e560');
    app()->instance('env', 'production');
    Http::fake();

    $this->artisan('billing:facturapi-test-cancellation-identity-audit', ['invoice' => $invoice->id])
        ->assertExitCode(1);

    Http::assertNothingSent();
});
