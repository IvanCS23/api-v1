<?php

use App\Exceptions\Billing\InvoiceFiscalSnapshotIncompleteException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Billing\InvoicePacReadinessService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Support\Facades\Http;

/**
 * InvoicePacReadinessService (Fase 6.2.3): centraliza "Invoice lista
 * fiscalmente para el PAC" — antes disperso dentro de
 * FacturapiProvider::assertSnapshotIsComplete() (Fase 6.2.1/6.2.2, ya
 * eliminado de ese Provider). Puramente de lectura: nunca hace HTTP.
 */
function invoiceWithItemForReadinessTest(array $invoiceOverrides = [], array $itemOverrides = []): Invoice
{
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(array_merge(['company_id' => $company->id], $invoiceOverrides));
    InvoiceItem::factory()->create(array_merge(['company_id' => $company->id, 'invoice_id' => $invoice->id], $itemOverrides));

    app(CurrentTenant::class)->set($company->id);

    return $invoice->fresh(['items']);
}

test('evaluate(): una Invoice completa (snapshot + item completos) está ready, sin errores', function () {
    $invoice = invoiceWithItemForReadinessTest();

    $result = app(InvoicePacReadinessService::class)->evaluate($invoice);

    expect($result['ready'])->toBeTrue()
        ->and($result['errors'])->toBe([]);
});

test('assertReady(): una Invoice completa no lanza ninguna excepción', function () {
    $invoice = invoiceWithItemForReadinessTest();

    app(InvoicePacReadinessService::class)->assertReady($invoice);

    expect(true)->toBeTrue();
});

test('receptor incompleto: client_rfc/client_uso_cfdi vacíos producen errores con el code correcto', function () {
    // client_rfc/client_uso_cfdi son NOT NULL a nivel de esquema, se usa
    // '' para simular "dato ausente" sin violar esa constraint.
    $invoice = invoiceWithItemForReadinessTest(['client_rfc' => '', 'client_uso_cfdi' => '']);

    $result = app(InvoicePacReadinessService::class)->evaluate($invoice);

    expect($result['ready'])->toBeFalse();
    $codes = collect($result['errors'])->pluck('code');
    expect($codes)->toContain('INVOICE_CLIENT_RFC_MISSING')
        ->and($codes)->toContain('INVOICE_CLIENT_CFDI_USE_MISSING');
});

test('item incompleto: product_clave_producto/product_clave_unidad/product_objeto_imp vacíos producen errores', function () {
    $invoice = invoiceWithItemForReadinessTest([], [
        'product_clave_producto' => '',
        'product_clave_unidad' => '',
        'product_objeto_imp' => '',
    ]);

    $result = app(InvoicePacReadinessService::class)->evaluate($invoice);

    expect($result['ready'])->toBeFalse();
    $codes = collect($result['errors'])->pluck('code');
    expect($codes)->toContain('INVOICE_ITEM_PRODUCT_KEY_MISSING')
        ->and($codes)->toContain('INVOICE_ITEM_UNIT_KEY_MISSING')
        ->and($codes)->toContain('INVOICE_ITEM_TAX_OBJECT_MISSING');
});

test('item con cantidad o precio inválidos produce errores', function () {
    $invoice = invoiceWithItemForReadinessTest([], ['quantity' => 0, 'unit_price' => -10]);

    $result = app(InvoicePacReadinessService::class)->evaluate($invoice);

    $codes = collect($result['errors'])->pluck('code');
    expect($codes)->toContain('INVOICE_ITEM_INVALID_QUANTITY')
        ->and($codes)->toContain('INVOICE_ITEM_INVALID_UNIT_PRICE');
});

test('payment_form faltante produce INVOICE_PAYMENT_FORM_MISSING', function () {
    $invoice = invoiceWithItemForReadinessTest(['payment_form' => null]);

    $result = app(InvoicePacReadinessService::class)->evaluate($invoice);

    expect($result['ready'])->toBeFalse()
        ->and(collect($result['errors'])->pluck('code'))->toContain('INVOICE_PAYMENT_FORM_MISSING');
});

test('payment_form con formato inválido produce INVOICE_PAYMENT_FORM_INVALID_FORMAT', function (string $invalid) {
    $invoice = invoiceWithItemForReadinessTest(['payment_form' => $invalid]);

    $result = app(InvoicePacReadinessService::class)->evaluate($invoice);

    expect($result['ready'])->toBeFalse()
        ->and(collect($result['errors'])->pluck('code'))->toContain('INVOICE_PAYMENT_FORM_INVALID_FORMAT');
})->with(['3', '003', 'AB', '0A']);

test('payment_method inválido (ni PUE ni PPD) produce INVOICE_PAYMENT_METHOD_INVALID', function () {
    $invoice = invoiceWithItemForReadinessTest(['payment_method' => 'CASH']);

    $result = app(InvoicePacReadinessService::class)->evaluate($invoice);

    expect($result['ready'])->toBeFalse()
        ->and(collect($result['errors'])->pluck('code'))->toContain('INVOICE_PAYMENT_METHOD_INVALID');
});

test('payment_method null (omitido deliberadamente) NO produce ningún error', function () {
    $invoice = invoiceWithItemForReadinessTest(['payment_method' => null]);

    $result = app(InvoicePacReadinessService::class)->evaluate($invoice);

    expect(collect($result['errors'])->pluck('code'))->not->toContain('INVOICE_PAYMENT_METHOD_INVALID');
});

test('no items: una Invoice sin líneas produce INVOICE_NO_ITEMS', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);

    $result = app(InvoicePacReadinessService::class)->evaluate($invoice->fresh(['items']));

    expect($result['ready'])->toBeFalse()
        ->and(collect($result['errors'])->pluck('code'))->toContain('INVOICE_NO_ITEMS');
});

test('un tax_code no reconocido en el catálogo SAT c_Impuesto produce INVOICE_ITEM_TAX_CODE_UNRECOGNIZED', function () {
    $invoice = invoiceWithItemForReadinessTest([], ['tax_code' => '999']);

    $result = app(InvoicePacReadinessService::class)->evaluate($invoice);

    expect($result['ready'])->toBeFalse()
        ->and(collect($result['errors'])->pluck('code'))->toContain('INVOICE_ITEM_TAX_CODE_UNRECOGNIZED');
});

test('una línea con tax_type=retencion produce INVOICE_ITEM_WITHHOLDING_UNSUPPORTED', function () {
    $invoice = invoiceWithItemForReadinessTest([], [
        'tax_code' => '001',
        'tax_type' => \App\Enums\TaxType::Retencion,
    ]);

    $result = app(InvoicePacReadinessService::class)->evaluate($invoice);

    expect($result['ready'])->toBeFalse()
        ->and(collect($result['errors'])->pluck('code'))->toContain('INVOICE_ITEM_WITHHOLDING_UNSUPPORTED');
});

test('assertReady() lanza InvoiceFiscalSnapshotIncompleteException cuando no está ready, listando los campos', function () {
    $invoice = invoiceWithItemForReadinessTest(['payment_form' => null]);

    try {
        app(InvoicePacReadinessService::class)->assertReady($invoice);
        test()->fail('Se esperaba InvoiceFiscalSnapshotIncompleteException');
    } catch (InvoiceFiscalSnapshotIncompleteException $e) {
        expect($e->invoiceId)->toBe($invoice->id)
            ->and(implode(' ', $e->missingFields))->toContain('payment_form');
    }
});

test('integración: no HTTP cuando readiness falla — IssueInvoiceService bloquea antes de llamar al PAC', function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_READINESS_INTEGRATION',
    ]);

    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'status' => \App\Enums\InvoiceStatus::Issued,
        'payment_form' => null,
    ]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);
    app(CurrentTenant::class)->set($company->id);

    Http::fake();

    expect(fn () => app(\App\Services\Billing\IssueInvoiceService::class)->issue($invoice->fresh(['items'])))
        ->toThrow(InvoiceFiscalSnapshotIncompleteException::class);

    Http::assertNothingSent();
});
