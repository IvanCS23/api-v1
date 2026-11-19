<?php

use App\Models\Client;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductTax;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemTax;
use App\Models\User;
use App\Services\Taxes\DocumentTaxTotals;
use App\Services\Taxes\LineTaxCalculator;
use App\Services\Taxes\TaxSpec;
use App\Support\Tenant\CurrentTenant;

/**
 * Fase 6.34: los 8 casos del paso 7, calculados sólo en local con el
 * cálculo estilo SAT, contra los totales que devolvió Facturapi TEST
 * (borradores del 2026-11-10). Sin HTTP.
 */
function phase634Document(array $lines): array
{
    $calculator = new LineTaxCalculator;
    $models = [];
    foreach ($lines as [$quantity, $price, $discount, $taxes]) {
        $line = $calculator->calculate($quantity, $price, $discount, array_map(fn (array $t) => TaxSpec::fromArray($t), $taxes));
        $item = (new SaleItem)->forceFill(collect($line)->except(['taxes', 'base'])->all());
        $item->setRelation('taxes', collect($line['taxes'])->map(fn (array $row) => (new SaleItemTax)->forceFill($row)));
        $models[] = $item;
    }

    return (new DocumentTaxTotals)->compute($models);
}

$iva16 = ['tax' => '002', 'kind' => 'traslado', 'factor' => 'tasa', 'rate' => '0.16'];

test('los 8 casos del paso 7 coinciden con los totales de Facturapi', function (array $lines, string $facturapiTotal) {
    expect(phase634Document($lines)['total'])->toBe($facturapiTotal);
})->with([
    'A IVA 16 % con descuento' => [[[2, '100', '10', [$iva16]]], '220.40'],
    'B IVA exento' => [[[1, '250', '0', [['tax' => '002', 'kind' => 'traslado', 'factor' => 'exento']]]], '250.00'],
    'C IEPS 8 % + IVA' => [[[1, '100', '0', [['tax' => '003', 'kind' => 'traslado', 'factor' => 'tasa', 'rate' => '0.08'], $iva16]]], '125.28'],
    'D IEPS cuota + IVA' => [[[3, '10', '0', [['tax' => '003', 'kind' => 'traslado', 'factor' => 'cuota', 'rate' => '1.5'], $iva16]]], '40.02'],
    'E honorarios' => [[[1, '1000', '0', [$iva16, ['tax' => '002', 'kind' => 'retencion', 'factor' => 'tasa', 'rate' => '0.106667'], ['tax' => '001', 'kind' => 'retencion', 'factor' => 'tasa', 'rate' => '0.10']]]], '953.33'],
    'F RESICO' => [[[1, '1000', '0', [$iva16, ['tax' => '001', 'kind' => 'retencion', 'factor' => 'tasa', 'rate' => '0.0125']]]], '1147.50'],
    'G cantidad con 3 decimales' => [[['1.255', '10.10', '0', [$iva16]]], '14.71'],
    'H tres conceptos de 0.33' => [[[1, '0.33', '0', [$iva16]], [1, '0.33', '0', [$iva16]], [1, '0.33', '0', [$iva16]]], '1.15'],
]);

test('caso H: el documento redondea la suma de los exactos; las líneas visibles suman 1.14', function () use ($iva16) {
    $totals = phase634Document([[1, '0.33', '0', [$iva16]], [1, '0.33', '0', [$iva16]], [1, '0.33', '0', [$iva16]]]);
    $line = (new LineTaxCalculator)->calculate(1, '0.33', '0', [TaxSpec::fromArray($iva16)]);

    expect($totals)->toBe(['subtotal' => '0.99', 'discount_total' => '0.00', 'tax_total' => '0.16', 'taxes_transferred' => '0.16', 'taxes_withheld' => '0.00', 'total' => '1.15'])
        ->and($line['total'])->toBe('0.38') // 3 × 0.38 = 1.14 visibles
        ->and($line['taxes'][0]['amount'])->toBe('0.052800');
});

test('caso G: el impuesto es sobre el importe exacto y el documento da 14.71', function () use ($iva16) {
    expect(phase634Document([['1.255', '10.10', '0', [$iva16]]]))
        ->toMatchArray(['subtotal' => '12.68', 'taxes_transferred' => '2.03', 'total' => '14.71']);
});

test('caso H de punta a punta: la API guarda 1.15 y la venta se puede confirmar', function () use ($iva16) {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    app(CurrentTenant::class)->set($company->id);
    $product = Product::factory()->create(['company_id' => $company->id, 'objeto_imp' => '02', 'precio_unitario' => 0.33]);
    $tax = new ProductTax(['product_id' => $product->id, ...$iva16, 'position' => 0]);
    $tax->company_id = $company->id;
    $tax->save();

    $sale = $this->actingAs($user, 'api')->postJson('/api/sales', ['client_id' => $client->id])->json();
    foreach (range(1, 3) as $ignored) {
        $this->actingAs($user, 'api')->postJson("/api/sales/{$sale['id']}/items", ['product_id' => $product->id, 'quantity' => 1])
            ->assertCreated()->assertJsonPath('total', '0.38');
    }

    $this->actingAs($user, 'api')->getJson("/api/sales/{$sale['id']}")
        ->assertJsonPath('subtotal', '0.99')
        ->assertJsonPath('tax_total', '0.16')
        ->assertJsonPath('total', '1.15');

    // SaleWorkflow acepta los totales estilo SAT (antes exigía la suma de líneas).
    $this->actingAs($user, 'api')->postJson("/api/sales/{$sale['id']}/submit")->assertOk();
    $this->actingAs($user, 'api')->postJson("/api/sales/{$sale['id']}/confirm")->assertOk();
    app(CurrentTenant::class)->set($company->id);

    expect(Sale::findOrFail($sale['id'])->status->value)->toBe('confirmed');
    $this->artisan('billing:tax-lines-check')->assertSuccessful();
});
