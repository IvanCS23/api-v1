<?php

use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Support\Facades\Http;

/**
 * billing:facturapi-test-validate (Fase 6.2.3). Nunca hace HTTP en esta
 * fase (Facturapi no documenta dry_run) — solo valida localmente. Cero
 * persistencia: nunca escribe pac_external_id/cfdi_uuid/pac_issue_status.
 */
beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_MUY_SECRETA_COMMAND_5566',
    ]);

    Http::fake();
});

function readyInvoiceForCommandTest(): Invoice
{
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Issued,
        'client_rfc' => 'COMANDOSECRETO010101AAA',
        'client_calle' => 'Calle Confidencial Del Comando 456',
    ]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);

    return $invoice->fresh(['items']);
}

test('prohibido en production: falla inmediatamente, sin pedir confirmación ni tocar la Invoice', function () {
    $invoice = readyInvoiceForCommandTest();

    app()->instance('env', 'production');

    $this->artisan('billing:facturapi-test-validate', ['invoice' => $invoice->id])
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('falla sin FACTURAPI_TEST_KEY, con un mensaje sanitizado, antes de pedir confirmación', function () {
    config(['services.facturapi.test_key' => null]);
    $invoice = readyInvoiceForCommandTest();

    $this->artisan('billing:facturapi-test-validate', ['invoice' => $invoice->id])
        ->expectsOutputToContain('FACTURAPI_TEST_KEY no está configurada')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('invoice inexistente falla con un mensaje claro', function () {
    $this->artisan('billing:facturapi-test-validate', ['invoice' => 999999])
        ->assertExitCode(1);
});

test('requiere confirmación interactiva: si se rechaza, no ejecuta la validación ni hace nada más', function () {
    $invoice = readyInvoiceForCommandTest();

    $this->artisan('billing:facturapi-test-validate', ['invoice' => $invoice->id])
        ->expectsConfirmation('¿Continuar con la validación TEST local de la Invoice ['.$invoice->id.']?', 'no')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

test('con confirmación aceptada, una Invoice lista fiscalmente reporta VALID, sin HTTP y sin persistir nada', function () {
    $invoice = readyInvoiceForCommandTest();

    $this->artisan('billing:facturapi-test-validate', ['invoice' => $invoice->id])
        ->expectsConfirmation('¿Continuar con la validación TEST local de la Invoice ['.$invoice->id.']?', 'yes')
        ->expectsOutputToContain('VALID')
        ->assertExitCode(0);

    Http::assertNothingSent();

    $fresh = $invoice->fresh();
    expect($fresh->pac_external_id)->toBeNull()
        ->and($fresh->cfdi_uuid)->toBeNull()
        ->and($fresh->pac_issue_status)->toBeNull();
});

test('con confirmación aceptada, una Invoice incompleta (sin payment_form) reporta INVALID con el código de error', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Issued,
        'payment_form' => null,
    ]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);

    $this->artisan('billing:facturapi-test-validate', ['invoice' => $invoice->id])
        ->expectsConfirmation('¿Continuar con la validación TEST local de la Invoice ['.$invoice->id.']?', 'yes')
        ->expectsOutputToContain('INVALID')
        ->expectsOutputToContain('INVOICE_PAYMENT_FORM_MISSING')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('la salida nunca contiene la API key, el RFC, ni el domicilio real de la Invoice', function () {
    $invoice = readyInvoiceForCommandTest();

    $this->artisan('billing:facturapi-test-validate', ['invoice' => $invoice->id])
        ->expectsConfirmation('¿Continuar con la validación TEST local de la Invoice ['.$invoice->id.']?', 'yes')
        ->doesntExpectOutputToContain('COMANDOSECRETO010101AAA')
        ->doesntExpectOutputToContain('Calle Confidencial Del Comando')
        ->doesntExpectOutputToContain('sk_test_MUY_SECRETA_COMMAND_5566')
        ->assertExitCode(0);
});
