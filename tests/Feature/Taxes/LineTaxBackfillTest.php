<?php

use App\Enums\TaxFactorType;
use App\Enums\TaxType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\TaxRate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.34 (2): tablas de impuestos por producto y por línea, y el paso del
 * impuesto único de antes (tax_rate_id / snapshot) a ellas.
 */
function phase634Legacy(): array
{
    $company = Company::factory()->create();
    $iva16 = TaxRate::factory()->create(['code' => '002', 'name' => 'IVA 16%', 'rate' => 0.16, 'tax_type' => TaxType::Traslado, 'factor_type' => TaxFactorType::Tasa]);
    $exempt = TaxRate::factory()->create(['code' => '002', 'name' => 'IVA Exento', 'rate' => 0, 'tax_type' => TaxType::Traslado, 'factor_type' => TaxFactorType::Exento]);

    $product = Product::factory()->create(['company_id' => $company->id, 'tax_rate_id' => $iva16->id, 'objeto_imp' => '02']);
    $exemptProduct = Product::factory()->create(['company_id' => $company->id, 'tax_rate_id' => $exempt->id, 'objeto_imp' => '02']);
    // Columnas viejas con valores (como el producto 2 de desarrollo) y sin tax_rate_id.
    $legacyOnly = Product::factory()->create(['company_id' => $company->id, 'tax_rate_id' => null, 'iva_retenido' => 8, 'ieps' => 50, 'isr' => 5.4]);

    $sale = Sale::factory()->create(['company_id' => $company->id, 'subtotal' => 300, 'discount_total' => 10, 'tax_total' => 30.40, 'total' => 320.40]);
    $taxed = SaleItem::factory()->create(['company_id' => $company->id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'tax_rate_id' => $iva16->id,
        'quantity' => 2, 'unit_price' => 100, 'discount' => 10, 'subtotal' => 200, 'tax_total' => 30.40, 'total' => 220.40]);
    $exemptLine = SaleItem::factory()->create(['company_id' => $company->id, 'sale_id' => $sale->id, 'product_id' => $exemptProduct->id, 'tax_rate_id' => $exempt->id,
        'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100, 'tax_total' => 0, 'total' => 100]);

    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'subtotal' => 200, 'discount_total' => 10, 'tax_total' => 30.40, 'total' => 220.40]);
    // La factura usa su snapshot aunque la tasa del catálogo haya cambiado.
    $invoiceLine = InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id, 'product_id' => $product->id, 'tax_rate_id' => $iva16->id,
        'quantity' => 2, 'unit_price' => 100, 'discount' => 10, 'subtotal' => 200, 'tax_total' => 30.40, 'total' => 220.40,
        'tax_code' => '002', 'tax_name' => 'IVA 16%', 'tax_rate_value' => 0.16, 'tax_type' => 'traslado', 'tax_factor_type' => 'tasa']);
    $iva16->update(['rate' => 0.08]);

    return compact('company', 'product', 'exemptProduct', 'legacyOnly', 'sale', 'taxed', 'exemptLine', 'invoice', 'invoiceLine');
}

test('las tablas y columnas nuevas existen y las columnas viejas de products siguen intactas', function () {
    foreach (['product_taxes', 'quote_item_taxes', 'sale_item_taxes', 'invoice_item_taxes'] as $table) {
        expect(Schema::hasColumns($table, ['company_id', 'tax', 'kind', 'factor', 'rate', 'ieps_mode', 'position']))->toBeTrue($table);
    }
    expect(Schema::hasColumns('sale_item_taxes', ['base', 'amount', 'amount_rounded']))->toBeTrue()
        ->and(Schema::hasColumn('product_taxes', 'amount'))->toBeFalse();
    foreach (['quotes', 'quote_items', 'sales', 'sale_items', 'invoices', 'invoice_items'] as $table) {
        expect(Schema::hasColumns($table, ['tax_total', 'taxes_transferred', 'taxes_withheld']))->toBeTrue($table);
    }
    expect(Schema::hasColumns('products', ['iva', 'iva_retenido', 'ieps', 'isr', 'impuesto_local', 'cuenta_predial', 'no_pedimento', 'tax_rate_id']))->toBeTrue();
});

test('sin --apply no escribe nada', function () {
    phase634Legacy();

    $this->artisan('billing:backfill-line-taxes')->assertSuccessful();

    expect(DB::table('product_taxes')->count())->toBe(0)
        ->and(DB::table('sale_item_taxes')->count())->toBe(0)
        ->and(DB::table('sales')->where('taxes_transferred', '!=', 0)->count())->toBe(0);
});

test('con --apply copia las cifras tal cual, sin recalcular, y la verificación cuadra', function () {
    $data = phase634Legacy();
    $before = DB::table('invoices')->where('id', $data['invoice']->id)->first(['subtotal', 'tax_total', 'total', 'updated_at']);

    $this->artisan('billing:backfill-line-taxes --apply')->assertSuccessful();

    $productTax = DB::table('product_taxes')->where('product_id', $data['product']->id)->first();
    $exemptTax = DB::table('product_taxes')->where('product_id', $data['exemptProduct']->id)->first();
    expect($productTax)->toMatchArray(['tax' => '002', 'kind' => 'traslado', 'factor' => 'tasa', 'ieps_mode' => null])
        ->and((float) $productTax->rate)->toBe(0.08) // la tasa actual del catálogo (el producto apunta a ella)
        ->and($exemptTax->factor)->toBe('exento')
        ->and($exemptTax->rate)->toBeNull()
        // Las columnas viejas de products no se leen.
        ->and(DB::table('product_taxes')->where('product_id', $data['legacyOnly']->id)->exists())->toBeFalse();

    $lineTax = DB::table('sale_item_taxes')->where('sale_item_id', $data['taxed']->id)->first();
    expect((float) $lineTax->base)->toBe(190.0)
        ->and((float) $lineTax->amount)->toBe(30.40)
        ->and((float) $lineTax->amount_rounded)->toBe(30.40)
        ->and((float) DB::table('sale_items')->where('id', $data['taxed']->id)->value('taxes_transferred'))->toBe(30.40)
        ->and((float) DB::table('sales')->where('id', $data['sale']->id)->value('taxes_transferred'))->toBe(30.40);

    $exemptLineTax = DB::table('sale_item_taxes')->where('sale_item_id', $data['exemptLine']->id)->first();
    expect($exemptLineTax->amount)->toBeNull()->and((float) $exemptLineTax->amount_rounded)->toBe(0.0);

    // La factura usa su snapshot (16 %), no la tasa actual del catálogo (8 %),
    // y sus importes no cambian.
    $invoiceTax = DB::table('invoice_item_taxes')->where('invoice_item_id', $data['invoiceLine']->id)->first();
    $after = DB::table('invoices')->where('id', $data['invoice']->id)->first(['subtotal', 'tax_total', 'total', 'updated_at']);
    expect((float) $invoiceTax->rate)->toBe(0.16)
        ->and((float) $invoiceTax->amount)->toBe(30.40)
        ->and($after)->toEqual($before);

    $this->artisan('billing:tax-lines-check')->assertSuccessful();
});

test('es idempotente: una segunda pasada no agrega nada', function () {
    phase634Legacy();
    $this->artisan('billing:backfill-line-taxes --apply')->assertSuccessful();
    $counts = [DB::table('product_taxes')->count(), DB::table('sale_item_taxes')->count(), DB::table('invoice_item_taxes')->count()];

    $this->artisan('billing:backfill-line-taxes --apply')->assertSuccessful();

    expect([DB::table('product_taxes')->count(), DB::table('sale_item_taxes')->count(), DB::table('invoice_item_taxes')->count()])->toBe($counts);
});

test('una línea con retención detiene el backfill sin escribir nada', function () {
    $data = phase634Legacy();
    $retention = TaxRate::factory()->create(['code' => '001', 'name' => 'ISR Retenido 10%', 'rate' => 0.10, 'tax_type' => TaxType::Retencion, 'factor_type' => TaxFactorType::Tasa]);
    $line = SaleItem::factory()->create(['company_id' => $data['company']->id, 'sale_id' => $data['sale']->id, 'tax_rate_id' => $retention->id, 'subtotal' => 100, 'tax_total' => 10, 'total' => 110]);

    $this->artisan('billing:backfill-line-taxes --apply')
        ->expectsOutputToContain("sale_items #{$line->id}: tiene una retención")
        ->assertFailed();

    expect(DB::table('product_taxes')->count())->toBe(0)
        ->and(DB::table('sale_item_taxes')->count())->toBe(0);
});

test('la verificación detecta una línea que no cuadra', function () {
    $data = phase634Legacy();
    $this->artisan('billing:backfill-line-taxes --apply')->assertSuccessful();
    DB::table('sale_item_taxes')->where('sale_item_id', $data['taxed']->id)->update(['amount_rounded' => 30.41]);

    $this->artisan('billing:tax-lines-check')
        ->expectsOutputToContain("sale_items #{$data['taxed']->id}")
        ->assertFailed();
});
