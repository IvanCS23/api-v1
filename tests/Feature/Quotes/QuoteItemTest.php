<?php

use App\Enums\QuoteStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Product;
use App\Models\Quote;
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
