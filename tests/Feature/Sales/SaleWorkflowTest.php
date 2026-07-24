<?php

use App\Enums\SaleStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\Sales\SaleWorkflow;
use App\Support\Tenant\CurrentTenant;

test('submit transiciona Draft a Pending', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $sale = Sale::factory()->create(['company_id' => $company->id, 'status' => SaleStatus::Draft]);

    $response = $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/submit");

    $response->assertOk()->assertJsonPath('status', 'pending');
});

test('confirm transiciona Pending a Confirmed y fija confirmed_at', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $sale = Sale::factory()->create(['company_id' => $company->id, 'client_id' => $client->id, 'status' => SaleStatus::Draft]);
    $product = Product::factory()->create(['company_id' => $company->id, 'precio_unitario' => 100]);
    $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/items", ['product_id' => $product->id, 'quantity' => 1])->assertCreated();
    $sale->update(['status' => SaleStatus::Pending]);

    expect($sale->fresh()->confirmed_at)->toBeNull();

    $response = $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/confirm");

    $response->assertOk()->assertJsonPath('status', 'confirmed');
    expect($sale->fresh()->confirmed_at)->not->toBeNull();
});

test('cancel transiciona Draft o Pending a Cancelled y fija cancelled_at', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    $saleDraft = Sale::factory()->create(['company_id' => $company->id, 'status' => SaleStatus::Draft]);
    $responseDraft = $this->actingAs($user, 'api')->postJson("/api/sales/{$saleDraft->id}/cancel");
    $responseDraft->assertOk()->assertJsonPath('status', 'cancelled');
    expect($saleDraft->fresh()->cancelled_at)->not->toBeNull();

    $salePending = Sale::factory()->create(['company_id' => $company->id, 'status' => SaleStatus::Pending]);
    $responsePending = $this->actingAs($user, 'api')->postJson("/api/sales/{$salePending->id}/cancel");
    $responsePending->assertOk()->assertJsonPath('status', 'cancelled');
    expect($salePending->fresh()->cancelled_at)->not->toBeNull();
});

test('confirmed_at y cancelled_at solo se fijan por su transición correspondiente', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $sale = Sale::factory()->create(['company_id' => $company->id, 'status' => SaleStatus::Draft]);

    $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/cancel")->assertOk();

    $sale->refresh();
    expect($sale->cancelled_at)->not->toBeNull()
        ->and($sale->confirmed_at)->toBeNull();
});

test('transiciones inválidas de Sale son rechazadas con un error controlado', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    // Draft no puede confirmarse directamente (falta pasar por Pending).
    $draft = Sale::factory()->create(['company_id' => $company->id, 'status' => SaleStatus::Draft]);
    $this->actingAs($user, 'api')->postJson("/api/sales/{$draft->id}/confirm")->assertStatus(422);

    // Confirmed es terminal: no admite submit/confirm/cancel de nuevo.
    $confirmed = Sale::factory()->create(['company_id' => $company->id, 'status' => SaleStatus::Confirmed]);
    $this->actingAs($user, 'api')->postJson("/api/sales/{$confirmed->id}/submit")->assertStatus(422);
    $this->actingAs($user, 'api')->postJson("/api/sales/{$confirmed->id}/confirm")->assertStatus(422);
    $this->actingAs($user, 'api')->postJson("/api/sales/{$confirmed->id}/cancel")->assertStatus(422);

    // Cancelled es terminal: no admite ninguna transición.
    $cancelled = Sale::factory()->create(['company_id' => $company->id, 'status' => SaleStatus::Cancelled]);
    $this->actingAs($user, 'api')->postJson("/api/sales/{$cancelled->id}/submit")->assertStatus(422);
    $this->actingAs($user, 'api')->postJson("/api/sales/{$cancelled->id}/confirm")->assertStatus(422);
    $this->actingAs($user, 'api')->postJson("/api/sales/{$cancelled->id}/cancel")->assertStatus(422);
});

test('no se puede confirmar una venta sin líneas', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $sale = Sale::factory()->create(['company_id' => $company->id, 'status' => SaleStatus::Pending]);

    $response = $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/confirm");

    $response->assertStatus(422);
    expect($sale->fresh()->status)->toBe(SaleStatus::Pending);
});

test('no se puede confirmar una venta sin un cliente válido', function () {
    $company = Company::factory()->create();
    $client = Client::factory()->create(['company_id' => $company->id]);
    $sale = Sale::factory()->create(['company_id' => $company->id, 'client_id' => $client->id, 'status' => SaleStatus::Pending]);
    app(CurrentTenant::class)->set($company->id);
    \App\Models\SaleItem::factory()->create(['company_id' => $company->id, 'sale_id' => $sale->id]);
    $client->delete(); // soft delete: la relación client() deja de resolver.

    $workflow = app(SaleWorkflow::class);

    expect(fn () => $workflow->confirm($sale->fresh()))
        ->toThrow(\App\Exceptions\WorkflowTransitionException::class, 'sin un cliente válido');
});

test('no se puede confirmar una venta con importes incoherentes respecto a sus líneas', function () {
    $company = Company::factory()->create();
    $client = Client::factory()->create(['company_id' => $company->id]);
    $sale = Sale::factory()->create(['company_id' => $company->id, 'client_id' => $client->id, 'status' => SaleStatus::Pending, 'total' => 0, 'subtotal' => 0]);
    app(CurrentTenant::class)->set($company->id);
    \App\Models\SaleItem::factory()->create(['company_id' => $company->id, 'sale_id' => $sale->id, 'subtotal' => 100, 'total' => 100]);
    // $sale->total sigue en 0 porque no se volvió a recalcular tras crear la línea directo por factory (sin pasar por el controller).

    $workflow = app(SaleWorkflow::class);

    expect(fn () => $workflow->confirm($sale->fresh()))
        ->toThrow(\App\Exceptions\WorkflowTransitionException::class, 'importes incoherentes');
});
