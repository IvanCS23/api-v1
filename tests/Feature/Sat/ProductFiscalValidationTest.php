<?php

use App\Data\Billing\PacInvoiceRequest;
use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Scopes\CompanyScope;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\Tenant\CurrentTenant;
use Database\Seeders\TaxRateSeeder;
use Illuminate\Support\Facades\Http;

/*
| Fase 6.26: clave de producto y unidad contra los catálogos del SAT, objeto
| de impuesto coherente con el IVA, y el IVA como tasa de tax_rates para que
| "exento" no se confunda con la tasa 0 % ni termine guardado como 16 %.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    (new TaxRateSeeder)->run();
});

function satTaxRate(string $name): TaxRate
{
    return TaxRate::where('name', $name)->firstOrFail();
}

function productUser(): User
{
    return User::factory()->create(['company_id' => Company::factory()->create()->id]);
}

function fiscalProductPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Licencia anual',
        'descripcion' => 'Licencia de software',
        'precio_unitario' => 100,
        'clave_producto' => '43232408',
        'clave_unidad' => 'E48',
        'objeto_imp' => '02',
        'tax_rate_id' => satTaxRate('IVA 16%')->id,
    ], $overrides);
}

test('el IVA del producto se guarda como tasa: 16 %, 0 % y exento son distintos', function (string $rate, string $iva) {
    $taxRate = satTaxRate($rate);

    $response = $this->actingAs(productUser(), 'api')
        ->postJson('/api/products', fiscalProductPayload(['tax_rate_id' => $taxRate->id]))
        ->assertCreated()
        ->assertJsonPath('tax_rate_id', $taxRate->id);

    $product = Product::withoutGlobalScope(CompanyScope::class)->findOrFail($response->json('id'));
    expect($product->tax_rate_id)->toBe($taxRate->id)
        ->and($product->taxRate->name)->toBe($rate)
        ->and($product->iva)->toBe($iva);
})->with([
    'IVA 16 %' => ['IVA 16%', '16.00'],
    'IVA 0 %' => ['IVA 0%', '0.00'],
    'IVA exento' => ['IVA Exento', '0.00'],
]);

test('el campo iva heredado se traduce a su tasa (16 → 16 %, 0 → tasa 0 %, nunca exento)', function () {
    $user = productUser();
    $payload = fiscalProductPayload();
    unset($payload['tax_rate_id']);

    $sixteen = $this->actingAs($user, 'api')->postJson('/api/products', [...$payload, 'iva' => 16])->assertCreated();
    $zero = $this->actingAs($user, 'api')->postJson('/api/products', [...$payload, 'iva' => 0])->assertCreated();

    expect($sixteen->json('tax_rate_id'))->toBe(satTaxRate('IVA 16%')->id)
        ->and($zero->json('tax_rate_id'))->toBe(satTaxRate('IVA 0%')->id);
});

test('"Exento" de la pantalla anterior (iva null) ya no se guarda como 16 %: se rechaza', function () {
    $payload = fiscalProductPayload(['iva' => null]);
    unset($payload['tax_rate_id']);

    $this->actingAs(productUser(), 'api')
        ->postJson('/api/products', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['tax_rate_id' => 'objeto de impuesto 02']);

    expect(Product::withoutGlobalScope(CompanyScope::class)->count())->toBe(0);
});

test('editar con iva null ya no responde 500: el iva crudo nunca llega a la columna', function () {
    $user = productUser();
    $product = Product::factory()->create(['company_id' => $user->company_id, 'clave_unidad' => 'H87', 'tax_rate_id' => satTaxRate('IVA 16%')->id]);

    $this->actingAs($user, 'api')
        ->putJson("/api/products/{$product->id}", ['name' => 'Renombrado', 'iva' => null, 'objeto_imp' => '02'])
        ->assertOk()
        ->assertJsonPath('name', 'Renombrado');

    expect($product->fresh()->iva)->toBe('16.00');
});

test('sin tasa (objeto 01) el iva derivado es 0, nunca el default 16 de la columna', function () {
    $payload = fiscalProductPayload(['objeto_imp' => '01']);
    unset($payload['tax_rate_id']);

    $response = $this->actingAs(productUser(), 'api')->postJson('/api/products', $payload)->assertCreated();

    $product = Product::withoutGlobalScope(CompanyScope::class)->findOrFail($response->json('id'));
    expect($product->tax_rate_id)->toBeNull()
        ->and($product->iva)->toBe('0.00');
});

test('una retención no puede ser el IVA del producto', function () {
    $this->actingAs(productUser(), 'api')
        ->postJson('/api/products', fiscalProductPayload(['tax_rate_id' => satTaxRate('ISR Retenido 10%')->id]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['tax_rate_id' => 'no existe o no está activa']);
});

test('la clave de producto debe tener 8 dígitos y existir en c_ClaveProdServ', function (string $key, string $message) {
    $this->actingAs(productUser(), 'api')
        ->postJson('/api/products', fiscalProductPayload(['clave_producto' => $key]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['clave_producto' => $message]);
})->with([
    'letras' => ['dasdsada', '8 dígitos'],
    'longitud' => ['4323240', 'must be 8 characters'],
    'inexistente' => ['99999999', 'no existe en el catálogo c_ClaveProdServ'],
]);

test('la unidad es obligatoria, se normaliza a mayúsculas y debe existir en c_ClaveUnidad', function () {
    $user = productUser();
    $payload = fiscalProductPayload();
    unset($payload['clave_unidad']);

    $this->actingAs($user, 'api')->postJson('/api/products', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors('clave_unidad');

    $this->actingAs($user, 'api')->postJson('/api/products', fiscalProductPayload(['clave_unidad' => '888']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['clave_unidad' => 'no existe en el catálogo c_ClaveUnidad']);

    $this->actingAs($user, 'api')->postJson('/api/products', fiscalProductPayload(['clave_unidad' => 'h87']))
        ->assertCreated()
        ->assertJsonPath('clave_unidad', 'H87');
});

test('el objeto de impuesto debe existir y ser coherente con el IVA', function (array $overrides, string $field, string $message) {
    if (isset($overrides['tax_rate'])) {
        $overrides['tax_rate_id'] = satTaxRate($overrides['tax_rate'])->id;
        unset($overrides['tax_rate']);
    }

    $this->actingAs(productUser(), 'api')
        ->postJson('/api/products', fiscalProductPayload($overrides))
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field => $message]);
})->with([
    'fuera de catálogo' => [['objeto_imp' => '09'], 'objeto_imp', 'no existe en el catálogo c_ObjetoImp'],
    '02 sin IVA' => [['tax_rate_id' => null], 'tax_rate_id', 'debe tener IVA'],
    '01 con IVA' => [['objeto_imp' => '01'], 'tax_rate_id', 'no lleva IVA'],
    '03 con exento' => [['objeto_imp' => '03', 'tax_rate' => 'IVA Exento'], 'tax_rate_id', 'no lleva IVA'],
]);

test('editar sólo datos no fiscales de un producto inválido no exige corregirlo; editar uno fiscal sí', function () {
    $user = productUser();
    $product = Product::factory()->create(['company_id' => $user->company_id, 'clave_producto' => 'dasdsada', 'clave_unidad' => '888']);

    $this->actingAs($user, 'api')->putJson("/api/products/{$product->id}", ['precio_unitario' => 250])->assertOk();

    $this->actingAs($user, 'api')->putJson("/api/products/{$product->id}", ['clave_unidad' => 'H87'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['clave_producto' => '8 dígitos']);
});

test('16 %, 0 % y exento llegan distintos a la factura y al PAC', function (string $rate, array $expectedTax) {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $taxRate = satTaxRate($rate);

    $productId = $this->actingAs($user, 'api')
        ->postJson('/api/products', fiscalProductPayload(['tax_rate_id' => $taxRate->id]))
        ->assertCreated()
        ->json('id');
    $saleId = $this->actingAs($user, 'api')
        ->postJson('/api/sales', ['client_id' => $client->id, 'payment_form' => '03', 'payment_method' => 'PUE'])
        ->json('id');
    $this->actingAs($user, 'api')
        ->postJson("/api/sales/{$saleId}/items", ['product_id' => $productId, 'quantity' => 1, 'tax_rate_id' => $taxRate->id])
        ->assertCreated();

    $invoiceId = $this->actingAs($user, 'api')->postJson("/api/sales/{$saleId}/invoice")->assertCreated()->json('id');

    // El tenant se limpia al terminar cada petición; las líneas lo necesitan.
    app(CurrentTenant::class)->set($company->id);
    $invoice = Invoice::withoutGlobalScope(CompanyScope::class)->with('items')->findOrFail($invoiceId);
    expect($invoice->items[0]->tax_factor_type)->toBe($taxRate->factor_type->value)
        ->and($invoice->items[0]->product_objeto_imp)->toBe('02');

    Http::fake(['*' => Http::response(['id' => 'inv_tax', 'status' => 'pending'], 200)]);
    facturapiProviderFromConfig()->createInvoice(new PacInvoiceRequest(invoice: $invoice, idempotencyKey: "erp-invoice:{$company->id}:{$invoice->id}:v1", externalId: 'ext-tax'));

    Http::assertSent(function ($request) use ($expectedTax) {
        expect($request->data()['items'][0]['product']['taxes'])->toBe([$expectedTax]);

        return true;
    });
})->with([
    'IVA 16 %' => ['IVA 16%', ['type' => 'IVA', 'rate' => 0.16]],
    'IVA 0 %' => ['IVA 0%', ['type' => 'IVA', 'rate' => 0.0]],
    'IVA exento' => ['IVA Exento', ['type' => 'IVA', 'rate' => 0.0, 'factor' => 'Exento']],
]);

test('actualizar sin enviar retenciones, IEPS, ISR, predial, pedimento ni impuestos locales los conserva', function () {
    // Fase 6.26.1: el frontend ya no envía esos campos (no llegan a la
    // factura); lo capturado antes debe quedar intacto en el producto.
    $user = productUser();
    $product = Product::factory()->create([
        'company_id' => $user->company_id,
        'iva_retenido' => 8,
        'ieps' => 50,
        'isr' => 5.4,
        'cuenta_predial' => 'CP-001',
        'no_pedimento' => '23 47 3807 3000123',
        'impuesto_local' => '[{"nombre":"ISH","tasa":"0.03"}]',
    ]);

    $this->actingAs($user, 'api')
        ->putJson("/api/products/{$product->id}", fiscalProductPayload(['name' => 'Hospedaje']))
        ->assertOk()
        ->assertJsonPath('name', 'Hospedaje');

    expect($product->fresh()->only(['iva_retenido', 'ieps', 'isr', 'cuenta_predial', 'no_pedimento', 'impuesto_local']))->toBe([
        'iva_retenido' => '8.00',
        'ieps' => '50.00',
        'isr' => '5.40',
        'cuenta_predial' => 'CP-001',
        'no_pedimento' => '23 47 3807 3000123',
        'impuesto_local' => '[{"nombre":"ISH","tasa":"0.03"}]',
    ]);
});
