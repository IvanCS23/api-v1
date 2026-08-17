<?php

use App\Enums\InvoiceStatus;
use App\Enums\TaxFactorType;
use App\Enums\TaxType;
use App\Exceptions\SaleAlreadyInvoicedException;
use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Scopes\CompanyScope;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Billing\SaleToInvoiceConverter;
use App\Support\Tenant\CurrentTenant;

/**
 * Crea una Sale Confirmed con billing-readiness ready=true, pasando
 * siempre por el flujo real (store -> item -> submit -> confirm) para
 * que subtotal/tax_total/total queden coherentes de forma genuina.
 */
function billableSale(User $user, Client $client, Product $product, ?TaxRate $taxRate = null): Sale
{
    $payload = ['product_id' => $product->id, 'quantity' => 2];
    if ($taxRate !== null) {
        $payload['tax_rate_id'] = $taxRate->id;
    }

    $sale = test()->actingAs($user, 'api')->postJson('/api/sales', ['client_id' => $client->id])->json();
    test()->actingAs($user, 'api')->postJson("/api/sales/{$sale['id']}/items", $payload)->assertCreated();
    test()->actingAs($user, 'api')->postJson("/api/sales/{$sale['id']}/submit")->assertOk();
    test()->actingAs($user, 'api')->postJson("/api/sales/{$sale['id']}/confirm")->assertOk();

    app(CurrentTenant::class)->set($client->company_id);

    return Sale::findOrFail($sale['id']);
}

test('crear una factura desde una venta confirmada copia el snapshot completo', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $product = Product::factory()->create(['company_id' => $company->id, 'precio_unitario' => 150, 'clave_unidad' => 'H87']);
    $sale = billableSale($user, $client, $product);

    $response = $this->actingAs($user, 'api')->postJson('/api/invoices', ['sale_id' => $sale->id]);

    $response->assertCreated()
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('folio', 'FAC-00000001')
        ->assertJsonPath('company_id', $company->id)
        ->assertJsonPath('sale_id', $sale->id)
        ->assertJsonPath('client_id', $client->id)
        ->assertJsonPath('subtotal', '300.00')
        ->assertJsonPath('total', '300.00')
        ->assertJsonPath('currency', 'MXN');

    app(CurrentTenant::class)->set($company->id);
    $invoice = Invoice::withoutGlobalScope(CompanyScope::class)->with('items')->findOrFail($response->json('id'));
    expect($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->items)->toHaveCount(1);
});

test('una venta no lista para facturarse (ready=false) rechaza la conversión con los errores de billing readiness', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id, 'rfc' => '']);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $sale = billableSale($user, $client, $product);

    $response = $this->actingAs($user, 'api')->postJson('/api/invoices', ['sale_id' => $sale->id]);

    $response->assertStatus(422);
    expect(collect($response->json('errors'))->pluck('code'))->toContain('CLIENT_RFC_MISSING');

    app(CurrentTenant::class)->set($company->id);
    expect(Invoice::where('sale_id', $sale->id)->exists())->toBeFalse();
});

test('una venta sin confirmar (Draft) es rechazada por billing readiness al intentar facturar', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $sale = Sale::factory()->create(['company_id' => $company->id, 'client_id' => $client->id]);

    $response = $this->actingAs($user, 'api')->postJson('/api/invoices', ['sale_id' => $sale->id]);

    $response->assertStatus(422);
    expect(collect($response->json('errors'))->pluck('code'))->toContain('SALE_NOT_CONFIRMED');
});

test('el snapshot del cliente en la factura no cambia si el cliente se modifica después', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id, 'name' => 'Cliente Original SA', 'rfc' => 'AAA010101AAA']);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $sale = billableSale($user, $client, $product);

    $response = $this->actingAs($user, 'api')->postJson('/api/invoices', ['sale_id' => $sale->id]);
    $response->assertCreated()
        ->assertJsonPath('client_name', 'Cliente Original SA')
        ->assertJsonPath('client_rfc', 'AAA010101AAA');

    $invoiceId = $response->json('id');

    app(CurrentTenant::class)->set($company->id);
    $client->update(['name' => 'Cliente Renombrado SA', 'rfc' => 'BBB020202BBB']);

    $invoice = Invoice::findOrFail($invoiceId);
    expect($invoice->client_name)->toBe('Cliente Original SA')
        ->and($invoice->client_rfc)->toBe('AAA010101AAA');
});

test('el snapshot del producto en la línea de factura copia clave_producto, clave_unidad y type', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $product = Product::factory()->create([
        'company_id' => $company->id,
        'clave_producto' => 'ABC12345',
        'clave_unidad' => 'H87',
        'type' => 'service',
    ]);
    $sale = billableSale($user, $client, $product);

    $response = $this->actingAs($user, 'api')->postJson('/api/invoices', ['sale_id' => $sale->id]);
    $response->assertCreated();

    app(CurrentTenant::class)->set($company->id);
    $invoice = Invoice::with('items')->findOrFail($response->json('id'));
    $item = $invoice->items->first();

    expect($item->product_clave_producto)->toBe('ABC12345')
        ->and($item->product_clave_unidad)->toBe('H87')
        ->and($item->product_type)->toBe('service');

    // El snapshot no cambia si el producto se modifica después.
    $product->update(['clave_producto' => 'ZZZ99999', 'clave_unidad' => 'XYZ']);
    expect($item->fresh()->product_clave_producto)->toBe('ABC12345')
        ->and($item->fresh()->product_clave_unidad)->toBe('H87');
});

test('el snapshot de impuestos en la línea de factura copia code, name, rate, tax_type y factor_type', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $taxRate = TaxRate::factory()->create([
        'code' => '002',
        'name' => 'IVA Trasladado 16%',
        'rate' => 0.16,
        'tax_type' => TaxType::Traslado,
        'factor_type' => TaxFactorType::Tasa,
    ]);
    $sale = billableSale($user, $client, $product, $taxRate);

    $response = $this->actingAs($user, 'api')->postJson('/api/invoices', ['sale_id' => $sale->id]);
    $response->assertCreated();

    app(CurrentTenant::class)->set($company->id);
    $invoice = Invoice::with('items')->findOrFail($response->json('id'));
    $item = $invoice->items->first();

    expect($item->tax_code)->toBe('002')
        ->and($item->tax_name)->toBe('IVA Trasladado 16%')
        ->and((float) $item->tax_rate_value)->toBe(0.16)
        ->and($item->tax_type)->toBe('traslado')
        ->and($item->tax_factor_type)->toBe('tasa');

    // El snapshot no cambia si la tasa se desactiva/modifica después.
    $taxRate->update(['name' => 'Renombrada', 'active' => false]);
    expect($item->fresh()->tax_name)->toBe('IVA Trasladado 16%');
});

test('los folios de factura son consecutivos e independientes por empresa', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $userA = User::factory()->create(['company_id' => $companyA->id]);
    $userB = User::factory()->create(['company_id' => $companyB->id]);
    $clientA = Client::factory()->create(['company_id' => $companyA->id]);
    $clientB = Client::factory()->create(['company_id' => $companyB->id]);
    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);

    $saleA1 = billableSale($userA, $clientA, $productA);
    $saleA2 = billableSale($userA, $clientA, $productA);
    $saleB1 = billableSale($userB, $clientB, $productB);

    $invoiceA1 = $this->actingAs($userA, 'api')->postJson('/api/invoices', ['sale_id' => $saleA1->id]);
    $invoiceA2 = $this->actingAs($userA, 'api')->postJson('/api/invoices', ['sale_id' => $saleA2->id]);
    $invoiceB1 = $this->actingAs($userB, 'api')->postJson('/api/invoices', ['sale_id' => $saleB1->id]);

    expect($invoiceA1->json('folio'))->toBe('FAC-00000001')
        ->and($invoiceA2->json('folio'))->toBe('FAC-00000002')
        ->and($invoiceB1->json('folio'))->toBe('FAC-00000001');
});

test('una empresa no puede listar ni ver facturas de otra empresa', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $userB = User::factory()->create(['company_id' => $companyB->id]);

    $invoiceA = Invoice::factory()->create(['company_id' => $companyA->id]);
    Invoice::factory()->count(2)->create(['company_id' => $companyA->id]);
    Invoice::factory()->create(['company_id' => $companyB->id]);

    $index = $this->actingAs($userB, 'api')->getJson('/api/invoices');
    $index->assertOk();
    expect($index->json('data'))->toHaveCount(1);

    $this->actingAs($userB, 'api')->getJson("/api/invoices/{$invoiceA->id}")->assertNotFound();
});

test('convertir dos veces la misma venta no crea una segunda factura y devuelve un error controlado', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $sale = billableSale($user, $client, $product);

    $first = $this->actingAs($user, 'api')->postJson('/api/invoices', ['sale_id' => $sale->id]);
    $first->assertCreated();
    $firstInvoiceId = $first->json('id');

    $second = $this->actingAs($user, 'api')->postJson('/api/invoices', ['sale_id' => $sale->id]);
    $second->assertStatus(422);

    app(CurrentTenant::class)->set($company->id);
    expect(Invoice::where('sale_id', $sale->id)->count())->toBe(1)
        ->and(Invoice::where('sale_id', $sale->id)->first()->id)->toBe($firstInvoiceId);
});

test('el converter rechaza directamente una segunda conversión bajo el lock (defensa en profundidad)', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $sale = billableSale($user, $client, $product);

    $converter = app(SaleToInvoiceConverter::class);
    $invoice = $converter->convert($sale->fresh());

    expect(fn () => $converter->convert($sale->fresh()))
        ->toThrow(SaleAlreadyInvoicedException::class);

    expect(Invoice::where('sale_id', $sale->id)->count())->toBe(1)
        ->and(Invoice::where('sale_id', $sale->id)->first()->id)->toBe($invoice->id);
});

test('una factura Issued es completamente inmutable', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $sale = billableSale($user, $client, $product);

    $created = $this->actingAs($user, 'api')->postJson('/api/invoices', ['sale_id' => $sale->id]);
    $invoiceId = $created->json('id');

    $this->actingAs($user, 'api')->postJson("/api/invoices/{$invoiceId}/ready")->assertOk()->assertJsonPath('status', 'ready');
    $this->actingAs($user, 'api')->postJson("/api/invoices/{$invoiceId}/issue")->assertOk()->assertJsonPath('status', 'issued');

    app(CurrentTenant::class)->set($company->id);
    $issuedAt = Invoice::findOrFail($invoiceId)->issued_at;
    expect($issuedAt)->not->toBeNull();

    // No admite edición de datos.
    $this->actingAs($user, 'api')->putJson("/api/invoices/{$invoiceId}", ['notes' => 'intento post-issue'])
        ->assertStatus(422);

    // No admite eliminación.
    $this->actingAs($user, 'api')->deleteJson("/api/invoices/{$invoiceId}")
        ->assertStatus(422);

    // No admite ninguna transición adicional.
    $this->actingAs($user, 'api')->postJson("/api/invoices/{$invoiceId}/ready")->assertStatus(422);
    $this->actingAs($user, 'api')->postJson("/api/invoices/{$invoiceId}/issue")->assertStatus(422);
    $this->actingAs($user, 'api')->postJson("/api/invoices/{$invoiceId}/cancel")->assertStatus(422);

    app(CurrentTenant::class)->set($company->id);
    expect(Invoice::findOrFail($invoiceId)->issued_at->equalTo($issuedAt))->toBeTrue();
});
