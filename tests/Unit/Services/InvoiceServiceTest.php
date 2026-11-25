<?php

use App\Enums\InvoiceStatus;
use App\Exceptions\InvoiceNotEditableException;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\Billing\InvoiceService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// tests/Pest.php solo extiende TestCase en Feature: aquí se declara a mano.
uses(TestCase::class, RefreshDatabase::class);

/*
 * El 200/422 por HTTP lo cubre InvoiceCommercialDetailApiTest; aquí se
 * prueba el servicio y el mensaje exacto de la excepción.
 */
function invoiceServiceForCompany(Company $company): InvoiceService
{
    app(CurrentTenant::class)->set($company->id);

    return app(InvoiceService::class);
}

afterEach(function () {
    app(CurrentTenant::class)->clear();
});

test('update edita una factura editable y carga sus partidas', function (InvoiceStatus $status) {
    $company = Company::factory()->create();
    $service = invoiceServiceForCompany($company);
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => $status]);

    $updated = $service->update($invoice, ['notes' => 'Nota actualizada']);

    expect($updated->notes)->toBe('Nota actualizada')
        ->and($updated->relationLoaded('items'))->toBeTrue()
        ->and($invoice->fresh()->notes)->toBe('Nota actualizada');
})->with([InvoiceStatus::Draft, InvoiceStatus::Ready]);

test('update en una factura no editable lanza InvoiceNotEditableException y no guarda', function (InvoiceStatus $status) {
    $company = Company::factory()->create();
    $service = invoiceServiceForCompany($company);
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => $status, 'notes' => 'Original']);

    expect(fn () => $service->update($invoice, ['notes' => 'No permitido']))
        ->toThrow(InvoiceNotEditableException::class, 'Esta factura ya no se puede editar en su estado actual.');

    expect($invoice->fresh()->notes)->toBe('Original');
})->with([InvoiceStatus::Issued, InvoiceStatus::Cancelled]);
