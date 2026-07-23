<?php

use App\Enums\QuoteStatus;
use App\Enums\SaleStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Sale;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use App\Support\Tenant\CurrentTenant;

test('convertir una cotización aprobada crea una venta con los mismos datos y marca la cotización como converted', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'client_id' => $client->id, 'status' => QuoteStatus::Draft]);
    $product = Product::factory()->create(['company_id' => $company->id, 'precio_unitario' => 100]);

    $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/items", ['product_id' => $product->id, 'quantity' => 3])->assertCreated();

    $quote->update(['status' => QuoteStatus::Sent]);
    $quote->update(['status' => QuoteStatus::Approved]);

    $response = $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/convert");

    $response->assertCreated()
        ->assertJsonPath('status', 'confirmed')
        ->assertJsonPath('client_id', $client->id)
        ->assertJsonPath('subtotal', '300.00')
        ->assertJsonPath('total', '300.00');

    $saleId = $response->json('id');
    $sale = Sale::withoutGlobalScope(CompanyScope::class)->findOrFail($saleId);
    app(CurrentTenant::class)->set($company->id);

    expect($sale->status)->toBe(SaleStatus::Confirmed)
        ->and($sale->items()->count())->toBe(1);

    $quote->refresh();
    expect($quote->status)->toBe(QuoteStatus::Converted)
        ->and($quote->converted_sale_id)->toBe($saleId);
});

test('solo una cotización aprobada puede convertirse en venta', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'status' => QuoteStatus::Draft]);

    $response = $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/convert");

    $response->assertStatus(422);
    expect($quote->fresh()->status)->toBe(QuoteStatus::Draft);
});

test('una cotización convertida es inmutable: no admite ediciones ni cambios de líneas', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'client_id' => $client->id, 'status' => QuoteStatus::Approved]);
    $product = Product::factory()->create(['company_id' => $company->id]);

    $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/convert")->assertCreated();

    $quote->refresh();
    expect($quote->status)->toBe(QuoteStatus::Converted);

    // No admite edición de datos.
    $this->actingAs($user, 'api')->putJson("/api/quotes/{$quote->id}", ['notes' => 'intento post-conversión'])
        ->assertStatus(422);

    // No admite agregar productos.
    $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/items", ['product_id' => $product->id, 'quantity' => 1])
        ->assertStatus(422);

    // Reintentar convertir de nuevo tampoco procede (ya no está Approved).
    $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/convert")
        ->assertStatus(422);
});

test('convertir preserva la cotización original sin modificaciones estructurales (solo status y converted_sale_id)', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'client_id' => $client->id, 'status' => QuoteStatus::Draft, 'notes' => 'Notas originales']);
    $product = Product::factory()->create(['company_id' => $company->id, 'precio_unitario' => 40]);

    $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/items", ['product_id' => $product->id, 'quantity' => 2])->assertCreated();

    $quote->refresh();
    $subtotalBefore = (float) $quote->subtotal;
    $totalBefore = (float) $quote->total;
    $folioBefore = $quote->folio;
    $notesBefore = $quote->notes;

    $quote->update(['status' => QuoteStatus::Approved]);

    $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/convert")->assertCreated();

    $quote->refresh();
    app(CurrentTenant::class)->set($company->id);

    expect((float) $quote->subtotal)->toBe($subtotalBefore)
        ->and((float) $quote->total)->toBe($totalBefore)
        ->and($quote->folio)->toBe($folioBefore)
        ->and($quote->notes)->toBe($notesBefore)
        ->and($quote->items()->count())->toBe(1);
});
