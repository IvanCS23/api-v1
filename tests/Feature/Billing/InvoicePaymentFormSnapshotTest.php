<?php

use App\Contracts\Billing\PacProvider;
use App\Data\Billing\PacInvoiceRequest;
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

// Nota (Fase 6.2.3): las pruebas de "payment_form ausente/formato
// inválido bloquea" que vivían aquí se movieron a
// tests/Feature/Billing/InvoicePacReadinessServiceTest.php —
// FacturapiProvider ya NO valida esto (ver nota equivalente en
// FacturapiProviderPayloadTest.php).

/**
 * Política definitiva de payment_method (Fase 6.2.3): payment_form
 * obligatorio; payment_method nullable; si es null, NO se envía en el
 * payload (nunca se escribe "PUE" artificialmente en el snapshot ni en
 * el payload) — Facturapi aplica su propio default documentado.
 */
test('payment_method null: la propiedad payment_method está ausente del payload (Facturapi aplica su default PUE)', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'payment_form' => '03', 'payment_method' => null]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);
    app(CurrentTenant::class)->set($company->id);

    Http::fake(['*' => Http::response(['id' => 'inv_pm_null', 'status' => 'pending'], 200)]);

    $request = new PacInvoiceRequest(
        invoice: $invoice->fresh(['items']),
        idempotencyKey: "erp-invoice:{$company->id}:{$invoice->id}:v1",
        externalId: "company-{$company->id}-invoice-{$invoice->id}",
    );
    app(PacProvider::class)->createInvoice($request);

    Http::assertSent(fn ($request) => ! array_key_exists('payment_method', $request->data()));
});

test('payment_method PUE: el payload incluye "payment_method": "PUE"', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'payment_form' => '03', 'payment_method' => 'PUE']);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);
    app(CurrentTenant::class)->set($company->id);

    Http::fake(['*' => Http::response(['id' => 'inv_pm_pue', 'status' => 'pending'], 200)]);

    $request = new PacInvoiceRequest(
        invoice: $invoice->fresh(['items']),
        idempotencyKey: "erp-invoice:{$company->id}:{$invoice->id}:v1",
        externalId: "company-{$company->id}-invoice-{$invoice->id}",
    );
    app(PacProvider::class)->createInvoice($request);

    Http::assertSent(fn ($request) => ($request->data()['payment_method'] ?? null) === 'PUE');
});

test('payment_method PPD: el payload incluye "payment_method": "PPD"', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'payment_form' => '03', 'payment_method' => 'PPD']);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);
    app(CurrentTenant::class)->set($company->id);

    Http::fake(['*' => Http::response(['id' => 'inv_pm_ppd', 'status' => 'pending'], 200)]);

    $request = new PacInvoiceRequest(
        invoice: $invoice->fresh(['items']),
        idempotencyKey: "erp-invoice:{$company->id}:{$invoice->id}:v1",
        externalId: "company-{$company->id}-invoice-{$invoice->id}",
    );
    app(PacProvider::class)->createInvoice($request);

    Http::assertSent(fn ($request) => ($request->data()['payment_method'] ?? null) === 'PPD');
});
