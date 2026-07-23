<?php

use App\Enums\TaxFactorType;
use App\Enums\TaxType;
use App\Models\Company;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\TaxRate;
use App\Services\Sales\SaleCalculator;

test('calculateItem calcula subtotal, descuento, impuesto y total de una línea', function () {
    $calculator = new SaleCalculator();
    $taxRate = TaxRate::factory()->make(['rate' => 0.16, 'tax_type' => TaxType::Traslado, 'factor_type' => TaxFactorType::Tasa]);

    $result = $calculator->calculateItem(quantity: 3, unitPrice: 100, discount: 30, taxRate: $taxRate);

    // subtotal = 3*100 = 300; base gravable = 300-30 = 270; iva 16% = 43.2; total = 313.2
    expect($result['subtotal'])->toBe(300.0)
        ->and($result['discount'])->toBe(30.0)
        ->and($result['tax_total'])->toBe(43.2)
        ->and($result['total'])->toBe(313.2);
});

test('calculateItem sin tasa de impuesto no cobra impuesto', function () {
    $calculator = new SaleCalculator();

    $result = $calculator->calculateItem(quantity: 2, unitPrice: 50);

    expect($result['subtotal'])->toBe(100.0)
        ->and($result['tax_total'])->toBe(0.0)
        ->and($result['total'])->toBe(100.0);
});

test('calculateItem nunca deja una base gravable negativa si el descuento excede el subtotal', function () {
    $calculator = new SaleCalculator();

    $result = $calculator->calculateItem(quantity: 1, unitPrice: 10, discount: 100);

    expect($result['total'])->toBe(0.0);
});

test('recalculateSale suma los importes de todas las líneas de la venta', function () {
    $company = Company::factory()->create();
    $sale = Sale::factory()->create(['company_id' => $company->id]);

    SaleItem::factory()->create(['company_id' => $company->id, 'sale_id' => $sale->id, 'subtotal' => 100, 'discount' => 0, 'tax_total' => 16, 'total' => 116]);
    SaleItem::factory()->create(['company_id' => $company->id, 'sale_id' => $sale->id, 'subtotal' => 50, 'discount' => 5, 'tax_total' => 7.2, 'total' => 52.2]);

    // CompanyScope es fail-closed: fuera de una request HTTP no hay tenant
    // activo, así que $sale->items() devolvería 0 filas sin esto. En el
    // uso real (dentro de un controller), TenantMiddleware ya lo puebla.
    app(App\Support\Tenant\CurrentTenant::class)->set($company->id);

    $calculator = new SaleCalculator();
    $calculator->recalculateSale($sale)->save();

    $sale->refresh();

    expect((float) $sale->subtotal)->toBe(150.0)
        ->and((float) $sale->discount_total)->toBe(5.0)
        ->and((float) $sale->tax_total)->toBe(23.2)
        ->and((float) $sale->total)->toBe(168.2);
});
