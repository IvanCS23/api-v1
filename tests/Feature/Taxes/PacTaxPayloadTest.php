<?php

use App\Data\Billing\PacInvoiceRequest;
use App\Enums\InvoiceStatus;
use App\Exceptions\InvoicePacTotalMismatchException;
use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceItemTax;
use App\Models\Product;
use App\Models\ProductTax;
use App\Models\Sale;
use App\Models\User;
use App\Services\Billing\SaleBillingReadinessService;
use App\Services\Billing\SaleToInvoiceConverter;
use App\Services\Billing\StampPacDraftInvoiceService;
use App\Support\Billing\PacTotalTolerance;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Support\Facades\Http;

/**
 * Fase 6.34 (6): impuestos por concepto hacia Facturapi (withholding,
 * factor, ieps_mode), tax_included siempre false explícito, readiness sin
 * el bloqueo de retenciones, avisos legales y bloqueo por total distinto.
 */
beforeEach(function () {
    facturapiOrganizationsFromConfigKey();
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_PHASE634',
    ]);
});

/** Factura con una línea y estos impuestos en su snapshot por línea. */
function phase634Invoice(array $taxes, array $line = []): Invoice
{
    $company = Company::factory()->create();
    app(CurrentTenant::class)->set($company->id);
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    $item = InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id, 'quantity' => 1, 'unit_price' => 1000, 'subtotal' => 1000, ...$line]);
    foreach ($taxes as $position => $tax) {
        $row = new InvoiceItemTax(['invoice_item_id' => $item->id, ...$tax, 'position' => $position]);
        $row->company_id = $company->id;
        $row->save();
    }

    return $invoice->fresh(['items']);
}

function phase634SentProduct(Invoice $invoice): array
{
    Http::fake(['*' => Http::response(['id' => 'inv_634', 'status' => 'pending'], 200)]);
    facturapiProviderFromConfig()->createInvoice(new PacInvoiceRequest(invoice: $invoice, idempotencyKey: "erp-invoice:{$invoice->company_id}:{$invoice->id}:v1", externalId: 'ext-634'));

    $product = null;
    Http::assertSent(function ($request) use (&$product) {
        $product = $request->data()['items'][0]['product'];

        return true;
    });

    return $product;
}

test('honorarios: traslado y dos retenciones con withholding; tax_included false explícito', function () {
    $product = phase634SentProduct(phase634Invoice([
        ['tax' => '002', 'kind' => 'traslado', 'factor' => 'tasa', 'rate' => 0.16],
        ['tax' => '002', 'kind' => 'retencion', 'factor' => 'tasa', 'rate' => 0.106667],
        ['tax' => '001', 'kind' => 'retencion', 'factor' => 'tasa', 'rate' => 0.10],
    ]));

    expect($product['tax_included'])->toBeFalse()
        ->and($product['taxes'])->toBe([
            ['type' => 'IVA', 'rate' => 0.16],
            ['type' => 'IVA', 'rate' => 0.106667, 'withholding' => true],
            ['type' => 'ISR', 'rate' => 0.1, 'withholding' => true],
        ]);
});

test('IEPS por tasa y por cuota con ieps_mode, más IVA', function () {
    $product = phase634SentProduct(phase634Invoice([
        ['tax' => '003', 'kind' => 'traslado', 'factor' => 'tasa', 'rate' => 0.08, 'ieps_mode' => 'sum_before_taxes'],
        ['tax' => '003', 'kind' => 'traslado', 'factor' => 'cuota', 'rate' => 1.5, 'ieps_mode' => 'sum_before_taxes'],
        ['tax' => '002', 'kind' => 'traslado', 'factor' => 'tasa', 'rate' => 0.16],
    ]));

    expect($product['tax_included'])->toBeFalse()
        ->and($product['taxes'])->toBe([
            ['type' => 'IEPS', 'rate' => 0.08, 'ieps_mode' => 'sum_before_taxes'],
            ['type' => 'IEPS', 'rate' => 1.5, 'factor' => 'Cuota', 'ieps_mode' => 'sum_before_taxes'],
            ['type' => 'IVA', 'rate' => 0.16],
        ]);
});

test('exento: factor Exento y rate 0', function () {
    $product = phase634SentProduct(phase634Invoice([['tax' => '002', 'kind' => 'traslado', 'factor' => 'exento', 'rate' => null]]));

    expect($product['taxes'])->toBe([['type' => 'IVA', 'rate' => 0.0, 'factor' => 'Exento']])
        ->and($product['tax_included'])->toBeFalse();
});

test('una factura anterior a la 6.34 sin filas usa su snapshot de un solo impuesto', function () {
    $product = phase634SentProduct(phase634Invoice([], ['tax_code' => '002', 'tax_rate_value' => 0.16, 'tax_type' => 'traslado', 'tax_factor_type' => 'tasa']));

    expect($product['taxes'])->toBe([['type' => 'IVA', 'rate' => 0.16]])
        ->and($product['tax_included'])->toBeFalse();
});

test('sin impuestos: taxes vacío y tax_included false', function () {
    $product = phase634SentProduct(phase634Invoice([]));

    expect($product['taxes'])->toBe([])->and($product['tax_included'])->toBeFalse();
});

// ==================== Bloqueo por total distinto ====================

function phase634StampInvoice(string $localTotal): Invoice
{
    $company = Company::factory()->create();
    app(CurrentTenant::class)->set($company->id);
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued, 'total' => $localTotal]);
    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_draft_external_id' => 'inv_draft_634_'.$invoice->id,
        'pac_draft_idempotency_key' => "erp-invoice-draft:{$company->id}:{$invoice->id}:v1",
        'pac_draft_status' => 'draft',
        'pac_draft_ready_to_stamp' => true,
    ])->save();

    return $invoice->fresh();
}

test('un total del borrador distinto al local bloquea el timbrado, sin llamar a /stamp', function () {
    $invoice = phase634StampInvoice('953.33');
    Http::preventStrayRequests();
    Http::fake([
        "*/invoices/{$invoice->pac_draft_external_id}" => Http::response(['id' => $invoice->pac_draft_external_id, 'status' => 'draft', 'livemode' => false, 'is_ready_to_stamp' => true, 'total' => 953.34], 200),
    ]);

    expect(fn () => app(StampPacDraftInvoiceService::class)->stamp($invoice))
        ->toThrow(InvoicePacTotalMismatchException::class);

    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/stamp'));
    expect($invoice->fresh()->pac_issue_status)->toBeNull();
});

test('con el mismo total al centavo se timbra', function () {
    $invoice = phase634StampInvoice('953.33');
    Http::fake([
        "*/invoices/{$invoice->pac_draft_external_id}" => Http::response(['id' => $invoice->pac_draft_external_id, 'status' => 'draft', 'livemode' => false, 'is_ready_to_stamp' => true, 'total' => 953.33], 200),
        "*/invoices/{$invoice->pac_draft_external_id}/stamp" => Http::response(['id' => $invoice->pac_draft_external_id, 'status' => 'valid', 'livemode' => false, 'uuid' => 'BBBBBBBB-1111-2222-3333-444444444444', 'stamp' => ['date' => '2026-11-10T12:00:00Z']], 200),
    ]);

    expect(app(StampPacDraftInvoiceService::class)->stamp($invoice)->pac_status)->toBe('valid');
});

test('tolerancia: 0 centavos hasta definirla con el paso 7', function () {
    expect(PacTotalTolerance::MAX_DIFFERENCE_CENTS)->toBe(0)
        ->and(PacTotalTolerance::matches('953.33', 953.33))->toBeTrue()
        ->and(PacTotalTolerance::matches('953.33', '953.329'))->toBeTrue() // redondea a centavos
        ->and(PacTotalTolerance::matches('953.33', '953.34'))->toBeFalse()
        ->and(PacTotalTolerance::differenceCents('100.00', '99.98'))->toBe(2);
});

// ==================== Readiness y avisos legales ====================

function phase634BillableSale(array $taxes, array $client = []): array
{
    $company = Company::factory()->create(['rfc' => 'GODE561231GR8', 'fiscal_regime' => '612']);
    $user = User::factory()->create(['company_id' => $company->id]);
    $clientModel = Client::factory()->create(['company_id' => $company->id, ...$client]);
    app(CurrentTenant::class)->set($company->id);
    $product = Product::factory()->create(['company_id' => $company->id, 'objeto_imp' => '02', 'precio_unitario' => 1000, 'clave_unidad' => 'E48']);
    foreach ($taxes as $position => $tax) {
        $row = new ProductTax(['product_id' => $product->id, ...$tax, 'position' => $position]);
        $row->company_id = $company->id;
        $row->save();
    }

    $sale = test()->actingAs($user, 'api')->postJson('/api/sales', ['client_id' => $clientModel->id, 'payment_form' => '03', 'payment_method' => 'PUE'])->json();
    test()->actingAs($user, 'api')->postJson("/api/sales/{$sale['id']}/items", ['product_id' => $product->id, 'quantity' => 1])->assertCreated();
    test()->actingAs($user, 'api')->postJson("/api/sales/{$sale['id']}/submit")->assertOk();
    test()->actingAs($user, 'api')->postJson("/api/sales/{$sale['id']}/confirm")->assertOk();
    app(CurrentTenant::class)->set($company->id);

    return [$company, Sale::findOrFail($sale['id'])];
}

test('una venta con retenciones y receptor persona moral se factura sin avisos, con su snapshot completo', function () {
    [, $sale] = phase634BillableSale([
        ['tax' => '002', 'kind' => 'traslado', 'factor' => 'tasa', 'rate' => 0.16],
        ['tax' => '002', 'kind' => 'retencion', 'factor' => 'tasa', 'rate' => 0.106667],
        ['tax' => '001', 'kind' => 'retencion', 'factor' => 'tasa', 'rate' => 0.10],
    ], ['rfc' => 'ABC010101AB1', 'regimen_fiscal' => '601', 'uso_cfdi' => 'G03']);

    $readiness = app(SaleBillingReadinessService::class)->evaluate($sale, beforeInvoicing: true);
    expect($readiness['errors'])->toBe([])
        ->and(collect($readiness['warnings'])->pluck('code')->filter(fn ($c) => str_starts_with($c, 'LEGAL_'))->all())->toBe([]);

    $invoice = app(SaleToInvoiceConverter::class)->convert($sale);
    expect((float) $invoice->taxes_withheld)->toBe(206.67)
        ->and((float) $invoice->total)->toBe(953.33)
        ->and($invoice->items->first()->taxes)->toHaveCount(3);
});

test('retención con receptor persona física: aviso legal pendiente de contador, no error', function () {
    [, $sale] = phase634BillableSale([
        ['tax' => '002', 'kind' => 'traslado', 'factor' => 'tasa', 'rate' => 0.16],
        ['tax' => '001', 'kind' => 'retencion', 'factor' => 'tasa', 'rate' => 0.10],
    ], ['rfc' => 'MELM8305281H0', 'regimen_fiscal' => '612', 'uso_cfdi' => 'G03']);

    $readiness = app(SaleBillingReadinessService::class)->evaluate($sale, beforeInvoicing: true);
    $legal = collect($readiness['warnings'])->firstWhere('code', 'LEGAL_WITHHOLDING_RECEIVER_NOT_MORAL');

    expect($readiness['ready'])->toBeTrue()
        ->and($legal)->not->toBeNull()
        ->and($legal['pending_accountant'])->toBeTrue();
});

test('IEPS + IVA sin tax_rate_id pasa el objeto de impuesto 02 y se factura', function () {
    [, $sale] = phase634BillableSale([
        ['tax' => '003', 'kind' => 'traslado', 'factor' => 'tasa', 'rate' => 0.08, 'ieps_mode' => 'sum_before_taxes'],
        ['tax' => '002', 'kind' => 'traslado', 'factor' => 'tasa', 'rate' => 0.16],
    ], ['rfc' => 'ABC010101AB1', 'regimen_fiscal' => '601', 'uso_cfdi' => 'G03']);

    expect(app(SaleBillingReadinessService::class)->evaluate($sale, beforeInvoicing: true)['errors'])->toBe([]);
    expect((float) app(SaleToInvoiceConverter::class)->convert($sale)->total)->toBe(1252.8);
});
