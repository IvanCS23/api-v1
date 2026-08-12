<?php

use App\Enums\InvoicePacEventType;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\Billing\InvoicePacAuditService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.facturapi.test_key' => 'sk_test_COMMAND_AUDIT_SECRET']);
    Http::preventStrayRequests();
});

function invoiceForPacAuditCommand(): Invoice
{
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Issued,
    ]);
    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_audit_command_'.$invoice->id,
        'cfdi_uuid' => '96013e83-154b-4153-8e61-c38b8966e560',
        'pac_status' => 'canceled',
        'cancellation_status' => 'accepted',
        'pac_issue_status' => 'succeeded',
    ])->save();
    app(CurrentTenant::class)->set($company->id);

    return $invoice->fresh();
}

test('billing pac-audit muestra eventos sanitizados y hace cero HTTP', function () {
    $invoice = invoiceForPacAuditCommand();
    app(InvoicePacAuditService::class)->append($invoice, InvoicePacEventType::CancellationReceiptIdentityMismatch, [
        'receipt_uuid_count' => 1,
        'expected_uuid_masked' => '96013e83...e560',
        'note' => 'Bearer sk_test_COMMAND_AUDIT_SECRET',
    ], 'CANCELLATION_RECEIPT_UUID_MISMATCH');
    Http::fake();

    $this->artisan('billing:pac-audit', ['invoice' => $invoice->id])
        ->expectsOutputToContain('cancellation_receipt_identity_mismatch')
        ->expectsOutputToContain('PAC code')
        ->doesntExpectOutputToContain('sk_test_COMMAND_AUDIT_SECRET')
        ->doesntExpectOutputToContain('Bearer')
        ->doesntExpectOutputToContain((string) $invoice->cfdi_uuid)
        ->assertExitCode(0);

    Http::assertNothingSent();
});

test('billing pac-audit vacio no inventa eventos historicos y hace cero HTTP', function () {
    $invoice = invoiceForPacAuditCommand();
    Http::fake();

    $this->artisan('billing:pac-audit', ['invoice' => $invoice->id])
        ->expectsOutputToContain('no tiene eventos PAC registrados desde Fase 6.7')
        ->assertExitCode(0);

    Http::assertNothingSent();
});
