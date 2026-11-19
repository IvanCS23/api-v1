<?php

use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Billing\InvoicePacReadinessService;
use App\Services\Billing\SaleBillingReadinessService;
use App\Services\Sat\SatFiscalValidator;
use App\Support\Tenant\CurrentTenant;

/**
 * Fase 6.34: objeto de impuesto 05, 06 y 08 se rechazan con un error claro
 * hasta validar sus reglas, y no se ofrecen en la interfaz.
 */
test('un producto con objeto 05, 06 u 08 se rechaza con un error claro', function (string $object) {
    $user = User::factory()->create(['company_id' => Company::factory()->create()->id]);

    $this->actingAs($user, 'api')->postJson('/api/products', [
        'name' => 'X', 'descripcion' => 'X', 'precio_unitario' => 10,
        'clave_producto' => '43232408', 'clave_unidad' => 'E48', 'objeto_imp' => $object,
    ])->assertUnprocessable()
        ->assertJsonPath('errors.objeto_imp.0', "El objeto de impuesto {$object} todavía no está disponible en FacturaPro: usa 01, 02, 03, 04 o 07.");
})->with(['05', '06', '08']);

test('los objetos admitidos no cambian (01 y 07 siguen aceptándose)', function () {
    $validator = new SatFiscalValidator;

    expect(array_column($validator->productIssues(['objeto_imp' => '01']), 'code'))->toBe([])
        ->and(array_column($validator->productIssues(['objeto_imp' => '07']), 'code'))->toBe([])
        ->and(array_column($validator->productIssues(['objeto_imp' => '06']), 'code'))->toBe(['PRODUCT_TAX_OBJECT_UNSUPPORTED']);
});

test('una factura con un concepto de objeto 06 no se envía al PAC', function () {
    $company = Company::factory()->create();
    app(CurrentTenant::class)->set($company->id);
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id, 'product_objeto_imp' => '06']);

    $codes = collect(app(InvoicePacReadinessService::class)->evaluate($invoice->fresh())['errors'])->pluck('code');

    expect($codes)->toContain('INVOICE_ITEM_TAX_OBJECT_UNSUPPORTED');
});

test('una venta con un producto de objeto 06 no se puede facturar', function () {
    $company = Company::factory()->create();
    app(CurrentTenant::class)->set($company->id);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $sale = Sale::factory()->create(['company_id' => $company->id, 'client_id' => $client->id]);
    $product = Product::factory()->create(['company_id' => $company->id, 'objeto_imp' => '06']);
    SaleItem::factory()->create(['company_id' => $company->id, 'sale_id' => $sale->id, 'product_id' => $product->id]);

    $codes = collect(app(SaleBillingReadinessService::class)->evaluate($sale, beforeInvoicing: true)['errors'])->pluck('code');

    expect($codes)->toContain('ITEM_PRODUCT_OBJETO_IMP_UNSUPPORTED');
});
