<?php

use App\Enums\InvoiceStatus;
use App\Enums\SaleStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use App\Services\Billing\InvoiceWorkflow;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;

/**
 * Fase 6.25 — POST /api/sales/{sale}/invoice: venta → factura ready en una
 * sola operación atómica e idempotente, conservando draft → pending →
 * confirmed. Una venta cuya factura se borró puede facturarse de nuevo.
 */
beforeEach(fn () => Http::preventStrayRequests());

/** @return array{Company, User, Sale} */
function phase625OrchestrationSale(array $sale = ['payment_form' => '03', 'payment_method' => 'PUE'], bool $withItem = true): array
{
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $product = Product::factory()->create(['company_id' => $company->id, 'clave_unidad' => 'H87']);

    $created = test()->actingAs($user, 'api')->postJson('/api/sales', ['client_id' => $client->id, ...$sale])->assertCreated()->json();

    if ($withItem) {
        test()->actingAs($user, 'api')->postJson("/api/sales/{$created['id']}/items", ['product_id' => $product->id, 'quantity' => 2])->assertCreated();
    }

    return [$company, $user, Sale::withoutGlobalScopes()->findOrFail($created['id'])];
}

function phase625Invoices(Sale $sale, bool $withTrashed = false)
{
    // Sólo sin el scope de empresa: el de SoftDeletes debe seguir activo.
    $query = Invoice::withoutGlobalScope(CompanyScope::class)->where('sale_id', $sale->id);

    return ($withTrashed ? $query->withTrashed() : $query)->get();
}

test('una venta en borrador queda confirmada y con su factura ready en una sola llamada', function () {
    [, $user, $sale] = phase625OrchestrationSale();

    $response = $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/invoice")
        ->assertCreated()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('payment_form', '03')
        ->assertJsonPath('payment_method', 'PUE');

    $fresh = $sale->fresh();
    expect($fresh->status)->toBe(SaleStatus::Confirmed)
        ->and($fresh->confirmed_at)->not->toBeNull()
        ->and(phase625Invoices($sale))->toHaveCount(1)
        ->and(phase625Invoices($sale)->first()->id)->toBe($response->json('id'))
        ->and((float) phase625Invoices($sale)->first()->total)->toBe((float) $fresh->total);
});

test('también factura ventas pendientes y confirmadas sin factura', function (string $path) {
    [, $user, $sale] = phase625OrchestrationSale();
    foreach (array_filter(explode(',', $path)) as $action) {
        $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/{$action}")->assertOk();
    }

    $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/invoice")
        ->assertCreated()->assertJsonPath('status', 'ready');
    expect($sale->fresh()->status)->toBe(SaleStatus::Confirmed);
})->with(['pendiente' => 'submit', 'confirmada' => 'submit,confirm']);

test('repetir la llamada devuelve la misma factura con 200 y sin crear otra', function () {
    [, $user, $sale] = phase625OrchestrationSale();
    $first = $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/invoice")->assertCreated();

    $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/invoice")
        ->assertOk()
        ->assertJsonPath('id', $first->json('id'))
        ->assertJsonPath('folio', $first->json('folio'));

    expect(phase625Invoices($sale))->toHaveCount(1);
});

test('una factura existente en borrador (flujo anterior) se marca ready y se devuelve con 200', function () {
    [, $user, $sale] = phase625OrchestrationSale();
    $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/submit")->assertOk();
    $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/confirm")->assertOk();
    $draft = $this->actingAs($user, 'api')->postJson('/api/invoices', ['sale_id' => $sale->id])->assertCreated()->json();

    $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/invoice")
        ->assertOk()->assertJsonPath('id', $draft['id'])->assertJsonPath('status', 'ready');
});

test('una factura existente ready, issued o cancelled se devuelve con 200 sin tocarla', function (InvoiceStatus $status) {
    [, $user, $sale] = phase625OrchestrationSale();
    $invoiceId = $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/invoice")->assertCreated()->json('id');
    $invoice = Invoice::withoutGlobalScopes()->findOrFail($invoiceId);
    $invoice->forceFill(['status' => $status])->save();
    $before = $invoice->fresh()->getAttributes();

    $this->travel(5)->minutes();
    $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/invoice")
        ->assertOk()->assertJsonPath('id', $invoiceId)->assertJsonPath('status', $status->value);

    expect($invoice->fresh()->getAttributes())->toBe($before)
        ->and(phase625Invoices($sale))->toHaveCount(1);
})->with([InvoiceStatus::Ready, InvoiceStatus::Issued, InvoiceStatus::Cancelled]);

test('una venta no lista responde 422 con los problemas y queda sin cambios', function () {
    [, $user, $sale] = phase625OrchestrationSale(sale: []);

    $response = $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/invoice")
        ->assertUnprocessable()
        ->assertJsonPath('code', 'SALE_NOT_BILLABLE');

    expect(collect($response->json('errors'))->pluck('code')->all())->toContain('SALE_PAYMENT_FORM_MISSING', 'SALE_PAYMENT_METHOD_MISSING')
        ->and($sale->fresh()->status)->toBe(SaleStatus::Draft)
        ->and(phase625Invoices($sale, withTrashed: true))->toHaveCount(0);
});

test('una venta sin líneas no se factura', function () {
    [, $user, $sale] = phase625OrchestrationSale(withItem: false);

    $response = $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/invoice")->assertUnprocessable();
    expect(collect($response->json('errors'))->pluck('code')->all())->toContain('SALE_NO_ITEMS');
});

test('una venta cancelada responde 409 SALE_CANCELLED', function () {
    [, $user, $sale] = phase625OrchestrationSale();
    $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/cancel")->assertOk();

    $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/invoice")
        ->assertStatus(409)->assertJsonPath('code', 'SALE_CANCELLED');
    expect(phase625Invoices($sale, withTrashed: true))->toHaveCount(0);
});

test('un fallo a mitad revierte todo: la venta sigue en borrador y sin factura', function () {
    [, $user, $sale] = phase625OrchestrationSale();
    $this->mock(InvoiceWorkflow::class)->shouldReceive('markReady')->andThrow(new RuntimeException('fallo simulado'));

    $this->withoutExceptionHandling();
    expect(fn () => $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/invoice"))->toThrow(RuntimeException::class, 'fallo simulado');

    expect($sale->fresh()->status)->toBe(SaleStatus::Draft)
        ->and($sale->fresh()->confirmed_at)->toBeNull()
        ->and(phase625Invoices($sale, withTrashed: true))->toHaveCount(0);
});

test('una venta de otra empresa responde 404', function () {
    [, , $sale] = phase625OrchestrationSale();
    $stranger = User::factory()->create(['company_id' => Company::factory()->create()->id]);

    $this->actingAs($stranger, 'api')->postJson("/api/sales/{$sale->id}/invoice")->assertNotFound();
});

test('venta cuya factura se borró: conserva sale_id, sigue confirmada y se puede facturar de nuevo', function () {
    [, $user, $sale] = phase625OrchestrationSale();
    $first = $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/invoice")->assertCreated()->json();

    $this->actingAs($user, 'api')->deleteJson("/api/invoices/{$first['id']}")->assertNoContent();

    $trashed = Invoice::withoutGlobalScopes()->withTrashed()->findOrFail($first['id']);
    expect($trashed->trashed())->toBeTrue()
        ->and($trashed->sale_id)->toBe($sale->id)
        ->and($sale->fresh()->status)->toBe(SaleStatus::Confirmed);

    $second = $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/invoice")
        ->assertCreated()->assertJsonPath('status', 'ready')->json();

    expect($second['id'])->not->toBe($first['id'])
        ->and($second['folio'])->not->toBe($first['folio'])
        ->and(phase625Invoices($sale))->toHaveCount(1)
        ->and(phase625Invoices($sale, withTrashed: true))->toHaveCount(2);
});

test('el flujo anterior (POST /api/invoices) también vuelve a facturar tras el borrado, en vez de un 500', function () {
    [, $user, $sale] = phase625OrchestrationSale();
    $first = $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/invoice")->assertCreated()->json();
    $this->actingAs($user, 'api')->deleteJson("/api/invoices/{$first['id']}")->assertNoContent();

    $this->actingAs($user, 'api')->postJson('/api/invoices', ['sale_id' => $sale->id])->assertCreated();
});

test('dos facturas vigentes para la misma venta siguen siendo imposibles', function () {
    [$company, $user, $sale] = phase625OrchestrationSale();
    $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/invoice")->assertCreated();

    expect(fn () => Invoice::factory()->create(['company_id' => $company->id, 'sale_id' => $sale->id]))
        ->toThrow(QueryException::class);
});

test('tras facturar, la venta ya no admite cambios en sus líneas', function () {
    [, $user, $sale] = phase625OrchestrationSale();
    $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/invoice")->assertCreated();
    $product = Product::factory()->create(['company_id' => $sale->company_id]);
    $item = $sale->items()->withoutGlobalScopes()->first();

    $this->actingAs($user, 'api')->postJson("/api/sales/{$sale->id}/items", ['product_id' => $product->id, 'quantity' => 1])->assertUnprocessable();
    $this->actingAs($user, 'api')->putJson("/api/sales/{$sale->id}/items/{$item->id}", ['quantity' => 5])->assertUnprocessable();
    $this->actingAs($user, 'api')->deleteJson("/api/sales/{$sale->id}/items/{$item->id}")->assertUnprocessable();
});

test('borrar el último borrador de venta y crear otro no repite el folio', function () {
    [, $user, $sale] = phase625OrchestrationSale();
    $this->actingAs($user, 'api')->deleteJson("/api/sales/{$sale->id}")->assertNoContent();

    $next = $this->actingAs($user, 'api')
        ->postJson('/api/sales', ['client_id' => $sale->client_id])
        ->assertCreated()
        ->json('folio');

    expect($next)->not->toBe($sale->folio);
});
