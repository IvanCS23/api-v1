<?php

use App\Contracts\Billing\PacProvider;
use App\Data\Billing\PacInvoiceRequest;
use App\Exceptions\Billing\InvoiceFiscalSnapshotIncompleteException;
use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Support\Facades\Http;

/**
 * Fase 6.2.2: `payment_form`/`payment_method` como snapshot fiscal de
 * Invoice, copiados de `Company.default_payment_form`/
 * `default_payment_method` durante SaleToInvoiceConverter::convert()
 * (Sale no posee este dato directamente — ver docblock de
 * SaleToInvoiceConverter). Snapshot inmutable: una vez creada la
 * Invoice, ni IssueInvoiceService ni FacturapiProvider vuelven a
 * consultar Sale/Company.
 */
beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_PAYMENT_FORM',
    ]);
});

function billableSaleForPaymentFormTest(User $user, Client $client, Product $product): Sale
{
    $sale = test()->actingAs($user, 'api')->postJson('/api/sales', ['client_id' => $client->id])->json();
    test()->actingAs($user, 'api')->postJson("/api/sales/{$sale['id']}/items", ['product_id' => $product->id, 'quantity' => 2])->assertCreated();
    test()->actingAs($user, 'api')->postJson("/api/sales/{$sale['id']}/submit")->assertOk();
    test()->actingAs($user, 'api')->postJson("/api/sales/{$sale['id']}/confirm")->assertOk();

    return Sale::withoutGlobalScope(CompanyScope::class)->findOrFail($sale['id']);
}

test('SaleToInvoiceConverter copia payment_form/payment_method de Company.default_payment_form/default_payment_method al snapshot de Invoice', function () {
    $company = Company::factory()->create(['default_payment_form' => '03', 'default_payment_method' => 'PUE']);
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $sale = billableSaleForPaymentFormTest($user, $client, $product);

    $response = $this->actingAs($user, 'api')->postJson('/api/invoices', ['sale_id' => $sale->id]);

    $response->assertCreated();

    app(CurrentTenant::class)->set($company->id);
    $invoice = Invoice::withoutGlobalScope(CompanyScope::class)->findOrFail($response->json('id'));

    expect($invoice->payment_form)->toBe('03')
        ->and($invoice->payment_method)->toBe('PUE');
});

test('si la empresa no configuró default_payment_form, el snapshot de Invoice queda null — nunca se inventa un valor', function () {
    $company = Company::factory()->create(['default_payment_form' => null, 'default_payment_method' => null]);
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $sale = billableSaleForPaymentFormTest($user, $client, $product);

    $response = $this->actingAs($user, 'api')->postJson('/api/invoices', ['sale_id' => $sale->id]);

    $response->assertCreated();

    app(CurrentTenant::class)->set($company->id);
    $invoice = Invoice::withoutGlobalScope(CompanyScope::class)->findOrFail($response->json('id'));

    expect($invoice->payment_form)->toBeNull()
        ->and($invoice->payment_method)->toBeNull();
});

test('snapshot inmutable: modificar Company.default_payment_form después de crear la Invoice no cambia el snapshot ya congelado', function () {
    $company = Company::factory()->create(['default_payment_form' => '03', 'default_payment_method' => 'PUE']);
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $sale = billableSaleForPaymentFormTest($user, $client, $product);

    $response = $this->actingAs($user, 'api')->postJson('/api/invoices', ['sale_id' => $sale->id]);
    $invoiceId = $response->json('id');

    // Cambia el default de la empresa DESPUÉS de que la Invoice ya existe.
    $company->update(['default_payment_form' => '99', 'default_payment_method' => 'PPD']);

    app(CurrentTenant::class)->set($company->id);
    $invoice = Invoice::withoutGlobalScope(CompanyScope::class)->findOrFail($invoiceId);

    expect($invoice->payment_form)->toBe('03')
        ->and($invoice->payment_method)->toBe('PUE');
});

test('IssueInvoiceService/FacturapiProvider usan exclusivamente el snapshot de Invoice: cambiar Company después de Issued no altera el payload enviado al PAC', function () {
    $company = Company::factory()->create(['default_payment_form' => '03']);
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'status' => \App\Enums\InvoiceStatus::Issued,
        'payment_form' => '03',
    ]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);
    app(CurrentTenant::class)->set($company->id);

    // Cambia el default de la empresa DESPUÉS de que la Invoice ya tiene
    // su propio snapshot congelado.
    $company->update(['default_payment_form' => '99']);

    Http::fake(['*' => Http::response(['id' => 'inv_snapshot_check', 'status' => 'valid'], 200)]);

    app(\App\Services\Billing\IssueInvoiceService::class)->issue($invoice->fresh(['items']));

    Http::assertSent(fn ($request) => ($request->data()['payment_form'] ?? null) === '03');
});

test('el payload enviado a Facturapi incluye payment_form en la raíz', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'payment_form' => '04']);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);
    app(CurrentTenant::class)->set($company->id);

    Http::fake(['*' => Http::response(['id' => 'inv_payload_check', 'status' => 'pending'], 200)]);

    $request = new PacInvoiceRequest(
        invoice: $invoice->fresh(['items']),
        idempotencyKey: "erp-invoice:{$company->id}:{$invoice->id}:v1",
        externalId: "company-{$company->id}-invoice-{$invoice->id}",
    );
    app(PacProvider::class)->createInvoice($request);

    Http::assertSent(fn ($request) => ($request->data()['payment_form'] ?? null) === '04');
});

test('payment_form ausente (null) bloquea la emisión antes de cualquier llamada HTTP', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'payment_form' => null]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);
    app(CurrentTenant::class)->set($company->id);

    Http::fake();

    $request = new PacInvoiceRequest(
        invoice: $invoice->fresh(['items']),
        idempotencyKey: "erp-invoice:{$company->id}:{$invoice->id}:v1",
        externalId: "company-{$company->id}-invoice-{$invoice->id}",
    );

    try {
        app(PacProvider::class)->createInvoice($request);
        test()->fail('Se esperaba InvoiceFiscalSnapshotIncompleteException');
    } catch (InvoiceFiscalSnapshotIncompleteException $e) {
        expect(implode(' ', $e->missingFields))->toContain('payment_form');
    }

    Http::assertNothingSent();
});

test('payment_form con formato inválido (no 2 dígitos numéricos) bloquea la emisión antes de cualquier llamada HTTP', function (string $invalid) {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'payment_form' => $invalid]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);
    app(CurrentTenant::class)->set($company->id);

    Http::fake();

    $request = new PacInvoiceRequest(
        invoice: $invoice->fresh(['items']),
        idempotencyKey: "erp-invoice:{$company->id}:{$invoice->id}:v1",
        externalId: "company-{$company->id}-invoice-{$invoice->id}",
    );

    expect(fn () => app(PacProvider::class)->createInvoice($request))
        ->toThrow(InvoiceFiscalSnapshotIncompleteException::class);

    Http::assertNothingSent();
})->with(['3', '003', 'AB', '0A', '  ']);
