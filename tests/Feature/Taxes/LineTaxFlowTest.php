<?php

use App\Enums\QuoteStatus;
use App\Enums\TaxFactorType;
use App\Enums\TaxType;
use App\Models\Client;
use App\Models\Company;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductTax;
use App\Models\Quote;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Scopes\CompanyScope;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Billing\SaleToInvoiceConverter;
use App\Services\Sales\QuoteToSaleConverter;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Support\Facades\DB;

/**
 * Fase 6.34 (5): impuestos de producto → línea → venta → factura (snapshot).
 */
const PHASE_634_FEES = [
    ['tax' => '002', 'kind' => 'traslado', 'factor' => 'tasa', 'rate' => 0.16],
    ['tax' => '002', 'kind' => 'retencion', 'factor' => 'tasa', 'rate' => 0.106667],
    ['tax' => '001', 'kind' => 'retencion', 'factor' => 'tasa', 'rate' => 0.10],
];

function phase634Setup(): array
{
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);

    return [$company, $user, $client];
}

function phase634Product(Company $company, array $taxes, array $attributes = []): Product
{
    app(CurrentTenant::class)->set($company->id);
    $product = Product::factory()->create(['company_id' => $company->id, 'objeto_imp' => '02', 'precio_unitario' => 1000, 'clave_unidad' => 'E48', ...$attributes]);
    foreach ($taxes as $position => $tax) {
        $row = new ProductTax(['product_id' => $product->id, ...$tax, 'position' => $position]);
        $row->company_id = $company->id;
        $row->save();
    }

    return $product->fresh();
}

test('un producto se crea con varios impuestos y la respuesta los incluye', function () {
    [, $user] = phase634Setup();

    $response = $this->actingAs($user, 'api')->postJson('/api/products', [
        'name' => 'Asesoría', 'descripcion' => 'Honorarios', 'precio_unitario' => 1000,
        'clave_producto' => '43232408', 'clave_unidad' => 'E48', 'objeto_imp' => '02',
        'taxes' => PHASE_634_FEES,
    ]);

    $response->assertCreated()
        ->assertJsonCount(3, 'taxes')
        ->assertJsonPath('taxes.1', ['tax' => '002', 'kind' => 'retencion', 'factor' => 'tasa', 'rate' => '0.106667', 'ieps_mode' => null, 'position' => 1]);
    expect(DB::table('product_taxes')->where('product_id', $response->json('id'))->count())->toBe(3);
});

test('los impuestos del producto se validan con las reglas del SAT y el objeto de impuesto', function (array $payload, string $field) {
    [, $user] = phase634Setup();

    $this->actingAs($user, 'api')->postJson('/api/products', [
        'name' => 'X', 'descripcion' => 'X', 'precio_unitario' => 10,
        'clave_producto' => '43232408', 'clave_unidad' => 'E48', 'objeto_imp' => '02', ...$payload,
    ])->assertUnprocessable()->assertJsonValidationErrors([$field]);

    expect(DB::table('product_taxes')->count())->toBe(0);
})->with([
    'IVA 10 %' => [['taxes' => [['tax' => '002', 'kind' => 'traslado', 'factor' => 'tasa', 'rate' => 0.10]]], 'taxes.0.rate'],
    'retención fuera de la lista' => [['taxes' => [['tax' => '002', 'kind' => 'traslado', 'factor' => 'tasa', 'rate' => 0.16], ['tax' => '001', 'kind' => 'retencion', 'factor' => 'tasa', 'rate' => 0.2]]], 'taxes.1.rate'],
    'objeto 01 con impuestos' => [['objeto_imp' => '01', 'taxes' => [['tax' => '002', 'kind' => 'traslado', 'factor' => 'tasa', 'rate' => 0.16]]], 'taxes'],
    'objeto 02 sin impuestos' => [['taxes' => []], 'taxes'],
    'taxes y tax_rate_id a la vez' => [['tax_rate_id' => 1, 'taxes' => [['tax' => '002', 'kind' => 'traslado', 'factor' => 'tasa', 'rate' => 0.16]]], 'taxes'],
    'factor inválido' => [['taxes' => [['tax' => '002', 'kind' => 'traslado', 'factor' => 'otro', 'rate' => 0.16]]], 'taxes.0.factor'],
]);

test('compatibilidad: tax_rate_id (la pantalla actual) deja un solo impuesto en el producto', function () {
    [, $user] = phase634Setup();
    $iva = TaxRate::factory()->create(['code' => '002', 'rate' => 0.16, 'tax_type' => TaxType::Traslado, 'factor_type' => TaxFactorType::Tasa]);

    $response = $this->actingAs($user, 'api')->postJson('/api/products', [
        'name' => 'X', 'descripcion' => 'X', 'precio_unitario' => 10,
        'clave_producto' => '43232408', 'clave_unidad' => 'E48', 'objeto_imp' => '02', 'tax_rate_id' => $iva->id,
    ])->assertCreated();

    expect($response->json('taxes'))->toHaveCount(1)
        ->and($response->json('taxes.0'))->toMatchArray(['tax' => '002', 'kind' => 'traslado', 'factor' => 'tasa', 'rate' => '0.160000']);

    // Editar sin taxes ni tax_rate_id no toca los impuestos.
    $this->actingAs($user, 'api')->putJson("/api/products/{$response->json('id')}", ['name' => 'Y'])->assertOk()->assertJsonCount(1, 'taxes');
});

test('una línea sin taxes ni tax_rate_id toma los impuestos del producto', function () {
    [$company, $user, $client] = phase634Setup();
    $product = phase634Product($company, PHASE_634_FEES);
    $sale = Sale::factory()->create(['company_id' => $company->id, 'client_id' => $client->id]);

    $response = $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/items", ['product_id' => $product->id, 'quantity' => 1]);

    $response->assertCreated()
        ->assertJsonPath('tax_total', '160.00')
        ->assertJsonPath('taxes_transferred', '160.00')
        ->assertJsonPath('taxes_withheld', '206.67')
        ->assertJsonPath('total', '953.33')
        ->assertJsonCount(3, 'taxes')
        ->assertJsonPath('taxes.1.amount', '106.667000');

    $sale->refresh();
    expect((float) $sale->taxes_transferred)->toBe(160.0)
        ->and((float) $sale->taxes_withheld)->toBe(206.67)
        ->and((float) $sale->total)->toBe(953.33);
    $this->artisan('billing:tax-lines-check')->assertSuccessful();
});

test('compatibilidad: con tax_rate_id nulo la línea no lleva impuestos, aunque el producto sí', function () {
    [$company, $user, $client] = phase634Setup();
    $product = phase634Product($company, PHASE_634_FEES);
    $sale = Sale::factory()->create(['company_id' => $company->id, 'client_id' => $client->id]);

    $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/items", ['product_id' => $product->id, 'quantity' => 1, 'tax_rate_id' => null])
        ->assertCreated()
        ->assertJsonPath('total', '1000.00')
        ->assertJsonCount(0, 'taxes');
});

test('la línea acepta sus propios impuestos y al editarla conserva los que tiene', function () {
    [$company, $user, $client] = phase634Setup();
    $product = phase634Product($company, PHASE_634_FEES);
    $sale = Sale::factory()->create(['company_id' => $company->id, 'client_id' => $client->id]);

    $item = $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/items", [
        'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100,
        'taxes' => [['tax' => '003', 'kind' => 'traslado', 'factor' => 'tasa', 'rate' => 0.08], ['tax' => '002', 'kind' => 'traslado', 'factor' => 'tasa', 'rate' => 0.16]],
    ])->assertCreated()->assertJsonPath('total', '125.28')->json();

    // Cambia la cantidad: recalcula con los mismos impuestos, no con los del producto.
    $this->actingAs($user, 'api')->putJson("/api/sales/{$sale->id}/items/{$item['id']}", ['quantity' => 2])
        ->assertOk()
        ->assertJsonPath('total', '250.56')
        ->assertJsonCount(2, 'taxes');

    // Una tasa que el SAT no admite se rechaza.
    $this->actingAs($user, 'api')->putJson("/api/sales/{$sale->id}/items/{$item['id']}", ['taxes' => [['tax' => '003', 'kind' => 'traslado', 'factor' => 'tasa', 'rate' => 0.1]]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['taxes.0.rate']);
});

test('cotización → venta copia los impuestos de cada línea tal cual', function () {
    [$company, $user, $client] = phase634Setup();
    $product = phase634Product($company, PHASE_634_FEES);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'client_id' => $client->id]);
    $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/items", ['product_id' => $product->id, 'quantity' => 1])->assertCreated();
    app(CurrentTenant::class)->set($company->id);
    $quote->refresh()->forceFill(['status' => QuoteStatus::Approved])->save();

    $sale = app(QuoteToSaleConverter::class)->convert($quote->fresh());

    $line = SaleItem::withoutGlobalScope(CompanyScope::class)->where('sale_id', $sale->id)->firstOrFail();
    expect($line->taxes)->toHaveCount(3)
        ->and($line->taxes[1]->amount)->toBe('106.667000')
        ->and((float) $sale->taxes_withheld)->toBe(206.67)
        ->and((float) $sale->total)->toBe(953.33);
});

test('venta → factura: snapshot de impuestos inmutable aunque cambie el producto', function () {
    [$company, $user, $client] = phase634Setup();
    $iva = TaxRate::factory()->create(['code' => '002', 'rate' => 0.16, 'tax_type' => TaxType::Traslado, 'factor_type' => TaxFactorType::Tasa]);
    $product = phase634Product($company, [['tax' => '002', 'kind' => 'traslado', 'factor' => 'tasa', 'rate' => 0.16]], ['precio_unitario' => 150, 'clave_unidad' => 'H87']);

    $sale = test()->actingAs($user, 'api')->postJson('/api/sales', ['client_id' => $client->id])->json();
    $this->actingAs($user, 'api')->postJson("/api/sales/{$sale['id']}/items", ['product_id' => $product->id, 'quantity' => 2, 'tax_rate_id' => $iva->id])->assertCreated();
    $this->actingAs($user, 'api')->postJson("/api/sales/{$sale['id']}/submit")->assertOk();
    $this->actingAs($user, 'api')->postJson("/api/sales/{$sale['id']}/confirm")->assertOk();
    app(CurrentTenant::class)->set($company->id);

    $invoice = app(SaleToInvoiceConverter::class)->convert(Sale::findOrFail($sale['id']));

    $line = InvoiceItem::withoutGlobalScope(CompanyScope::class)->where('invoice_id', $invoice->id)->firstOrFail();
    expect($line->taxes)->toHaveCount(1)
        ->and($line->taxes[0]->rate)->toBe('0.160000')
        ->and($line->taxes[0]->amount_rounded)->toBe('48.00')
        ->and((float) $invoice->taxes_transferred)->toBe(48.0);

    // Cambia el producto: la factura no se mueve.
    DB::table('product_taxes')->where('product_id', $product->id)->update(['rate' => 0]);
    expect($line->fresh()->taxes[0]->rate)->toBe('0.160000');

    $this->artisan('billing:tax-lines-check')->assertSuccessful();
});
