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

test('convertir dos veces una cotización ya convertida no crea una segunda venta y devuelve un error controlado', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'client_id' => $client->id, 'status' => QuoteStatus::Approved]);

    $first = $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/convert");
    $first->assertCreated();
    $firstSaleId = $first->json('id');

    $second = $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/convert");
    $second->assertStatus(422);

    app(CurrentTenant::class)->set($company->id);
    expect(Sale::withoutGlobalScope(CompanyScope::class)->where('company_id', $company->id)->count())->toBe(1);

    $quote->refresh();
    expect($quote->status)->toBe(QuoteStatus::Converted)
        ->and($quote->converted_sale_id)->toBe($firstSaleId);
});

test('converted_at no cambia en un reintento de conversión fallido', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'client_id' => $client->id, 'status' => QuoteStatus::Approved]);

    $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/convert")->assertCreated();
    $convertedAtAfterFirst = $quote->fresh()->converted_at;
    expect($convertedAtAfterFirst)->not->toBeNull();

    $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/convert")->assertStatus(422);

    expect($quote->fresh()->converted_at->equalTo($convertedAtAfterFirst))->toBeTrue();
});

test('el converter rechaza directamente una segunda conversión aunque el controller no la filtrara antes (defensa en profundidad del lock)', function () {
    $company = Company::factory()->create();
    $client = Client::factory()->create(['company_id' => $company->id]);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'client_id' => $client->id, 'status' => QuoteStatus::Approved]);
    app(CurrentTenant::class)->set($company->id);

    $converter = app(\App\Services\Sales\QuoteToSaleConverter::class);
    $sale = $converter->convert($quote->fresh());

    expect(fn () => $converter->convert($quote->fresh()))
        ->toThrow(\App\Exceptions\QuoteAlreadyConvertedException::class);

    expect(Sale::where('company_id', $company->id)->count())->toBe(1)
        ->and($quote->fresh()->converted_sale_id)->toBe($sale->id);
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

test('la venta y sus líneas resultantes de la conversión heredan el company_id de la Quote, nunca del tenant ambiental', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'client_id' => $client->id, 'status' => QuoteStatus::Draft]);
    $product = Product::factory()->create(['company_id' => $company->id, 'precio_unitario' => 10]);

    $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/items", ['product_id' => $product->id, 'quantity' => 2])->assertCreated();
    $quote->update(['status' => QuoteStatus::Approved]);

    $response = $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/convert");
    $response->assertCreated();

    app(CurrentTenant::class)->set($company->id);
    $sale = Sale::withoutGlobalScope(CompanyScope::class)->with('items')->findOrFail($response->json('id'));

    expect($sale->company_id)->toBe($company->id)
        ->and($sale->items)->toHaveCount(1);

    foreach ($sale->items as $item) {
        expect($item->company_id)->toBe($sale->company_id);
    }
});
