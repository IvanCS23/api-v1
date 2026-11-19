<?php

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductTax;
use App\Models\Sale;
use App\Models\User;
use App\Services\Billing\SaleBillingReadinessService;
use App\Services\Billing\SaleToInvoiceConverter;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Support\Facades\DB;

/**
 * Fase 6.34 (decisión del 2026-11-19): una venta calculada con el método
 * anterior (suma de líneas redondeadas) se recalcula al facturar con el
 * cálculo estilo SAT; el readiness avisa con ambos totales y queda un evento
 * de auditoría. Las facturas emitidas nunca se recalculan.
 */
function phase634RecalcSale(): array
{
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id, 'rfc' => 'ABC010101AB1', 'regimen_fiscal' => '601', 'uso_cfdi' => 'G03']);
    app(CurrentTenant::class)->set($company->id);
    $product = Product::factory()->create(['company_id' => $company->id, 'objeto_imp' => '02', 'precio_unitario' => 0.33, 'clave_unidad' => 'E48']);
    $tax = new ProductTax(['product_id' => $product->id, 'tax' => '002', 'kind' => 'traslado', 'factor' => 'tasa', 'rate' => 0.16, 'position' => 0]);
    $tax->company_id = $company->id;
    $tax->save();

    // Caso H por el flujo real: tres conceptos de 0.33 con IVA.
    $sale = test()->actingAs($user, 'api')->postJson('/api/sales', ['client_id' => $client->id, 'payment_form' => '03', 'payment_method' => 'PUE'])->json();
    foreach (range(1, 3) as $ignored) {
        test()->actingAs($user, 'api')->postJson("/api/sales/{$sale['id']}/items", ['product_id' => $product->id, 'quantity' => 1])->assertCreated();
    }
    test()->actingAs($user, 'api')->postJson("/api/sales/{$sale['id']}/submit")->assertOk();
    test()->actingAs($user, 'api')->postJson("/api/sales/{$sale['id']}/confirm")->assertOk();
    app(CurrentTenant::class)->set($company->id);

    return [$company, $user, Sale::findOrFail($sale['id'])];
}

/** La deja como la habría guardado el cálculo anterior a la 6.34 (y el backfill). */
function phase634MakeLegacy(Sale $sale): void
{
    $itemIds = DB::table('sale_items')->where('sale_id', $sale->id)->pluck('id');
    DB::table('sale_items')->whereIn('id', $itemIds)->update(['subtotal_exact' => null, 'tax_total' => 0.05, 'taxes_transferred' => 0.05, 'total' => 0.38]);
    DB::table('sale_item_taxes')->whereIn('sale_item_id', $itemIds)->update(['amount' => 0.05, 'amount_rounded' => 0.05]);
    DB::table('sales')->where('id', $sale->id)->update(['subtotal' => 0.99, 'tax_total' => 0.15, 'taxes_transferred' => 0.15, 'total' => 1.14]);
}

test('el readiness avisa que la venta se recalculará, con ambos totales, sin bloquear', function () {
    [, , $sale] = phase634RecalcSale();
    phase634MakeLegacy($sale);

    $readiness = app(SaleBillingReadinessService::class)->evaluate($sale->fresh(), beforeInvoicing: true);
    $warning = collect($readiness['warnings'])->firstWhere('code', 'SALE_TOTALS_WILL_BE_RECALCULATED');

    expect($readiness['ready'])->toBeTrue()
        ->and($warning)->toMatchArray(['previous_total' => '1.14', 'new_total' => '1.15'])
        ->and($warning['message'])->toContain('1.14')->toContain('1.15');

    // El readiness no escribe nada.
    expect((float) $sale->fresh()->total)->toBe(1.14);
});

test('al facturar, la venta se recalcula, la factura nace con el total estilo SAT y queda el evento de auditoría', function () {
    [$company, $user, $sale] = phase634RecalcSale();
    phase634MakeLegacy($sale);

    $invoice = app(SaleToInvoiceConverter::class)->convert($sale->fresh(), $user->id);

    $sale->refresh();
    expect((float) $sale->total)->toBe(1.15)
        ->and((float) $sale->tax_total)->toBe(0.16)
        ->and((float) $invoice->total)->toBe(1.15)
        ->and((float) $invoice->taxes_transferred)->toBe(0.16);

    // Las líneas quedan con el importe exacto y el impuesto exacto.
    $line = $sale->items()->first();
    expect($line->subtotal_exact)->toBe('0.330000')
        ->and($line->taxes->first()->amount)->toBe('0.052800');

    $audit = AuditLog::query()->where('action', 'sale_totals_recalculated')->sole();
    expect($audit->company_id)->toBe($company->id)
        ->and($audit->user_id)->toBe($user->id)
        ->and($audit->metadata['sale_id'])->toBe($sale->id)
        ->and($audit->metadata['before']['total'])->toBe('1.14')
        ->and($audit->metadata['after']['total'])->toBe('1.15');

    $this->artisan('billing:tax-lines-check')->assertSuccessful();
});

test('una venta ya calculada estilo SAT no genera evento ni aviso', function () {
    [, $user, $sale] = phase634RecalcSale();

    $readiness = app(SaleBillingReadinessService::class)->evaluate($sale, beforeInvoicing: true);
    expect(collect($readiness['warnings'])->pluck('code'))->not->toContain('SALE_TOTALS_WILL_BE_RECALCULATED');

    $invoice = app(SaleToInvoiceConverter::class)->convert($sale, $user->id);

    expect((float) $invoice->total)->toBe(1.15)
        ->and(AuditLog::query()->where('action', 'sale_totals_recalculated')->count())->toBe(0);
});

test('las facturas ya emitidas nunca se recalculan', function () {
    [$company, $user, $sale] = phase634RecalcSale();
    // Factura de otra venta, emitida con el cálculo anterior (1.14).
    $issued = Invoice::factory()->create(['company_id' => $company->id, 'status' => 'issued', 'subtotal' => 0.99, 'tax_total' => 0.15, 'taxes_transferred' => 0.15, 'total' => 1.14]);
    $before = DB::table('invoices')->where('id', $issued->id)->first();

    phase634MakeLegacy($sale);
    app(SaleToInvoiceConverter::class)->convert($sale->fresh(), $user->id);

    expect(DB::table('invoices')->where('id', $issued->id)->first())->toEqual($before);
});
