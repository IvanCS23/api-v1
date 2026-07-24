<?php

use App\Enums\QuoteStatus;
use App\Enums\TaxFactorType;
use App\Enums\TaxType;
use App\Models\Client;
use App\Models\Company;
use App\Models\Product;
use App\Models\Quote;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\Tenant\CurrentTenant;

test('agregar un producto a una cotización crea la línea y recalcula los totales', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'client_id' => $client->id]);
    $product = Product::factory()->create(['company_id' => $company->id, 'precio_unitario' => 100]);

    $response = $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/items", [
        'product_id' => $product->id,
        'quantity' => 2,
    ]);

    $response->assertCreated()
        ->assertJsonPath('subtotal', '200.00')
        ->assertJsonPath('total', '200.00');

    $quote->refresh();
    expect((float) $quote->subtotal)->toBe(200.0)
        ->and((float) $quote->total)->toBe(200.0);
});

test('recalcular: agregar una segunda línea suma correctamente al total de la cotización', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'client_id' => $client->id]);
    $productA = Product::factory()->create(['company_id' => $company->id, 'precio_unitario' => 100]);
    $productB = Product::factory()->create(['company_id' => $company->id, 'precio_unitario' => 50]);

    $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/items", ['product_id' => $productA->id, 'quantity' => 1])->assertCreated();
    $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/items", ['product_id' => $productB->id, 'quantity' => 3])->assertCreated();

    $quote->refresh();
    app(CurrentTenant::class)->set($company->id);

    expect((float) $quote->subtotal)->toBe(250.0)
        ->and((float) $quote->total)->toBe(250.0)
        ->and($quote->items()->count())->toBe(2);
});

test('eliminar una línea recalcula los totales de la cotización hacia abajo', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'client_id' => $client->id]);
    $productA = Product::factory()->create(['company_id' => $company->id, 'precio_unitario' => 100]);
    $productB = Product::factory()->create(['company_id' => $company->id, 'precio_unitario' => 50]);

    $itemA = $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/items", ['product_id' => $productA->id, 'quantity' => 1])->json();
    $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/items", ['product_id' => $productB->id, 'quantity' => 1])->assertCreated();

    $quote->refresh();
    expect((float) $quote->total)->toBe(150.0);

    $this->actingAs($user, 'api')->deleteJson("/api/quotes/{$quote->id}/items/{$itemA['id']}")->assertNoContent();

    $quote->refresh();
    app(CurrentTenant::class)->set($company->id);

    expect((float) $quote->total)->toBe(50.0)
        ->and($quote->items()->count())->toBe(1);
});

test('un producto de otra empresa es rechazado con 422 al agregarlo a una cotización', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $companyA->id]);
    $client = Client::factory()->create(['company_id' => $companyA->id]);
    $quote = Quote::factory()->create(['company_id' => $companyA->id, 'client_id' => $client->id]);
    $foreignProduct = Product::factory()->create(['company_id' => $companyB->id]);

    $response = $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/items", [
        'product_id' => $foreignProduct->id,
        'quantity' => 1,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('product_id');
});

test('no se pueden agregar ni eliminar productos en una cotización aprobada, rechazada, expirada o convertida', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $product = Product::factory()->create(['company_id' => $company->id]);

    foreach ([QuoteStatus::Approved, QuoteStatus::Rejected, QuoteStatus::Expired, QuoteStatus::Converted] as $status) {
        $quote = Quote::factory()->create(['company_id' => $company->id, 'client_id' => $client->id, 'status' => $status]);

        $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/items", [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->assertStatus(422);
    }
});

test('actualizar cantidad de una línea de cotización recalcula subtotal y total', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'client_id' => $client->id]);
    $product = Product::factory()->create(['company_id' => $company->id, 'precio_unitario' => 100]);
    $item = $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/items", ['product_id' => $product->id, 'quantity' => 1])->json();

    $response = $this->actingAs($user, 'api')->putJson("/api/quotes/{$quote->id}/items/{$item['id']}", ['quantity' => 3]);

    $response->assertOk()
        ->assertJsonPath('quantity', '3.000')
        ->assertJsonPath('subtotal', '300.00')
        ->assertJsonPath('total', '300.00');

    $quote->refresh();
    expect((float) $quote->total)->toBe(300.0);
});

test('actualizar precio unitario de una línea de cotización recalcula los totales', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'client_id' => $client->id]);
    $product = Product::factory()->create(['company_id' => $company->id, 'precio_unitario' => 100]);
    $item = $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/items", ['product_id' => $product->id, 'quantity' => 2])->json();

    $response = $this->actingAs($user, 'api')->putJson("/api/quotes/{$quote->id}/items/{$item['id']}", ['unit_price' => 40]);

    $response->assertOk()
        ->assertJsonPath('unit_price', '40.00')
        ->assertJsonPath('subtotal', '80.00')
        ->assertJsonPath('total', '80.00');
});

test('actualizar el descuento de una línea de cotización recalcula el total', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'client_id' => $client->id]);
    $product = Product::factory()->create(['company_id' => $company->id, 'precio_unitario' => 100]);
    $item = $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/items", ['product_id' => $product->id, 'quantity' => 1])->json();

    $response = $this->actingAs($user, 'api')->putJson("/api/quotes/{$quote->id}/items/{$item['id']}", ['discount' => 25]);

    $response->assertOk()
        ->assertJsonPath('discount', '25.00')
        ->assertJsonPath('total', '75.00');
});

test('cambiar el impuesto de una línea de cotización recalcula tax_total y total', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'client_id' => $client->id]);
    $product = Product::factory()->create(['company_id' => $company->id, 'precio_unitario' => 100]);
    $taxRate = TaxRate::factory()->create(['rate' => 0.16, 'tax_type' => TaxType::Traslado, 'factor_type' => TaxFactorType::Tasa]);
    $item = $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/items", ['product_id' => $product->id, 'quantity' => 1])->json();

    $response = $this->actingAs($user, 'api')->putJson("/api/quotes/{$quote->id}/items/{$item['id']}", ['tax_rate_id' => $taxRate->id]);

    $response->assertOk()
        ->assertJsonPath('tax_total', '16.00')
        ->assertJsonPath('total', '116.00');

    $quote->refresh();
    expect((float) $quote->tax_total)->toBe(16.0);
});

test('actualizar una línea que pertenece a otra cotización devuelve 404', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $quoteA = Quote::factory()->create(['company_id' => $company->id, 'client_id' => $client->id]);
    $quoteB = Quote::factory()->create(['company_id' => $company->id, 'client_id' => $client->id]);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $itemOfQuoteB = $this->actingAs($user, 'api')->postJson("/api/quotes/{$quoteB->id}/items", ['product_id' => $product->id, 'quantity' => 1])->json();

    $response = $this->actingAs($user, 'api')->putJson("/api/quotes/{$quoteA->id}/items/{$itemOfQuoteB['id']}", ['quantity' => 5]);

    $response->assertNotFound();
});

test('un usuario de otra empresa no puede actualizar una línea de cotización ajena (aislamiento tenant)', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $userA = User::factory()->create(['company_id' => $companyA->id]);
    $userB = User::factory()->create(['company_id' => $companyB->id]);
    $clientA = Client::factory()->create(['company_id' => $companyA->id]);
    $quoteA = Quote::factory()->create(['company_id' => $companyA->id, 'client_id' => $clientA->id]);
    $productA = Product::factory()->create(['company_id' => $companyA->id, 'precio_unitario' => 100]);
    $itemA = $this->actingAs($userA, 'api')->postJson("/api/quotes/{$quoteA->id}/items", ['product_id' => $productA->id, 'quantity' => 1])->json();

    $response = $this->actingAs($userB, 'api')->putJson("/api/quotes/{$quoteA->id}/items/{$itemA['id']}", ['quantity' => 9]);

    $response->assertNotFound();
    app(CurrentTenant::class)->set($companyA->id);
    expect((float) \App\Models\QuoteItem::find($itemA['id'])->quantity)->toBe(1.0);
});

test('actualizar una línea de cotización con un producto de otra empresa es rechazado con 422', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $companyA->id]);
    $client = Client::factory()->create(['company_id' => $companyA->id]);
    $quote = Quote::factory()->create(['company_id' => $companyA->id, 'client_id' => $client->id]);
    $product = Product::factory()->create(['company_id' => $companyA->id]);
    $foreignProduct = Product::factory()->create(['company_id' => $companyB->id]);
    $item = $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/items", ['product_id' => $product->id, 'quantity' => 1])->json();

    $response = $this->actingAs($user, 'api')->putJson("/api/quotes/{$quote->id}/items/{$item['id']}", ['product_id' => $foreignProduct->id]);

    $response->assertStatus(422)->assertJsonValidationErrors('product_id');
});

test('no se puede actualizar una línea de una cotización aprobada (documento inmutable)', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'client_id' => $client->id]);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $item = $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/items", ['product_id' => $product->id, 'quantity' => 1])->json();
    $quote->update(['status' => QuoteStatus::Approved]);

    $response = $this->actingAs($user, 'api')->putJson("/api/quotes/{$quote->id}/items/{$item['id']}", ['quantity' => 9]);

    $response->assertStatus(422);
});

test('un company_id malicioso al actualizar una línea de cotización es ignorado', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $otherCompany = Company::factory()->create();
    $client = Client::factory()->create(['company_id' => $company->id]);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'client_id' => $client->id]);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $item = $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/items", ['product_id' => $product->id, 'quantity' => 1])->json();

    $response = $this->actingAs($user, 'api')->putJson("/api/quotes/{$quote->id}/items/{$item['id']}", [
        'quantity' => 2,
        'company_id' => $otherCompany->id,
    ]);

    $response->assertOk();
    app(CurrentTenant::class)->set($company->id);
    expect(\App\Models\QuoteItem::find($item['id'])->company_id)->toBe($company->id);
});
