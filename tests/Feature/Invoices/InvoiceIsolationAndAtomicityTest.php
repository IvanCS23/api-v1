<?php

use App\Enums\InvoiceStatus;
use App\Exceptions\SaleAlreadyInvoicedException;
use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use App\Services\Billing\SaleToInvoiceConverter;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

/**
 * Igual que billableSale() en InvoiceConversionTest.php, redeclarada
 * aquí bajo otro nombre para no acoplar este archivo a ese (ambas
 * funciones globales de Pest coexisten sin colisión).
 */
function billableSaleForIsolation(User $user, Client $client, Product $product): Sale
{
    $sale = test()->actingAs($user, 'api')->postJson('/api/sales', ['client_id' => $client->id])->json();
    test()->actingAs($user, 'api')->postJson("/api/sales/{$sale['id']}/items", ['product_id' => $product->id, 'quantity' => 1])->assertCreated();
    test()->actingAs($user, 'api')->postJson("/api/sales/{$sale['id']}/submit")->assertOk();
    test()->actingAs($user, 'api')->postJson("/api/sales/{$sale['id']}/confirm")->assertOk();

    app(CurrentTenant::class)->set($client->company_id);

    return Sale::findOrFail($sale['id']);
}

test('el converter no puede bloquear ni convertir una Sale de otra empresa aunque la instancia llegue con company_id manipulado', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $companyA->id]);
    $client = Client::factory()->create(['company_id' => $companyA->id]);
    $product = Product::factory()->create(['company_id' => $companyA->id]);
    $sale = billableSaleForIsolation($user, $client, $product);

    // Instancia "tenant-scoped" corrompida/manipulada: mismo id de fila
    // real (de la empresa A), company_id forzado a la empresa B.
    $tamperedSale = Sale::withoutGlobalScope(CompanyScope::class)->find($sale->id);
    $tamperedSale->company_id = $companyB->id;

    $converter = app(SaleToInvoiceConverter::class);

    expect(fn () => $converter->convert($tamperedSale))
        ->toThrow(ModelNotFoundException::class);

    app(CurrentTenant::class)->set($companyA->id);
    expect(Invoice::where('sale_id', $sale->id)->exists())->toBeFalse();
});

test('una Sale de la empresa A nunca produce una Invoice registrada bajo la empresa B', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $companyA->id]);
    $client = Client::factory()->create(['company_id' => $companyA->id]);
    $product = Product::factory()->create(['company_id' => $companyA->id]);
    $sale = billableSaleForIsolation($user, $client, $product);

    $response = $this->actingAs($user, 'api')->postJson('/api/invoices', ['sale_id' => $sale->id]);
    $response->assertCreated();

    app(CurrentTenant::class)->set($companyA->id);
    $invoice = Invoice::findOrFail($response->json('id'));
    expect($invoice->company_id)->toBe($companyA->id)
        ->and($invoice->company_id)->not->toBe($companyB->id);

    // Y es invisible para la empresa B por el Global Scope normal.
    app(CurrentTenant::class)->set($companyB->id);
    expect(Invoice::find($invoice->id))->toBeNull();
});

test('una línea corrupta cross-tenant aborta la conversión sin crear ninguna Invoice', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $companyA->id]);
    $client = Client::factory()->create(['company_id' => $companyA->id]);
    $product = Product::factory()->create(['company_id' => $companyA->id]);
    $sale = billableSaleForIsolation($user, $client, $product);

    app(CurrentTenant::class)->set($companyA->id);
    // Simula corrupción de datos con un UPDATE crudo (BelongsToCompany
    // revierte cualquier intento vía Eloquent ->update()) — mismo patrón
    // usado en SaleBillingReadinessTest.
    \Illuminate\Support\Facades\DB::table('sale_items')
        ->where('sale_id', $sale->id)
        ->update(['company_id' => $companyB->id]);

    $response = $this->actingAs($user, 'api')->postJson('/api/invoices', ['sale_id' => $sale->id]);

    $response->assertStatus(422);
    expect(collect($response->json('errors'))->pluck('code'))->toContain('TENANT_MISMATCH');

    app(CurrentTenant::class)->set($companyA->id);
    expect(Invoice::where('sale_id', $sale->id)->exists())->toBeFalse()
        ->and(InvoiceItem::withoutGlobalScope(CompanyScope::class)->where('company_id', $companyA->id)->exists())->toBeFalse();
});

test('una conversión bloqueada por billing readiness no deja ninguna Invoice ni InvoiceItem huérfano', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id, 'rfc' => '']);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $sale = billableSaleForIsolation($user, $client, $product);

    $this->actingAs($user, 'api')->postJson('/api/invoices', ['sale_id' => $sale->id])->assertStatus(422);

    app(CurrentTenant::class)->set($company->id);
    expect(Invoice::where('sale_id', $sale->id)->exists())->toBeFalse()
        ->and(InvoiceItem::withoutGlobalScope(CompanyScope::class)->where('company_id', $company->id)->exists())->toBeFalse();
});

test('UNIQUE(sale_id) en la base es la última defensa contra doble conversión, incluso si el código de aplicación se saltara', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Draft]);

    // Un segundo INSERT directo (bypaseando por completo la validación
    // del converter) con el mismo sale_id debe ser rechazado por la
    // propia base de datos.
    expect(fn () => Invoice::factory()->create(['company_id' => $company->id, 'sale_id' => $invoice->sale_id]))
        ->toThrow(QueryException::class);

    app(CurrentTenant::class)->set($company->id);
    expect(Invoice::where('sale_id', $invoice->sale_id)->count())->toBe(1);
});

test('el converter rechaza directamente una segunda conversión concurrente sin dejar una segunda Invoice', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $sale = billableSaleForIsolation($user, $client, $product);

    $converter = app(SaleToInvoiceConverter::class);
    $invoice = $converter->convert($sale->fresh());

    expect(fn () => $converter->convert($sale->fresh()))
        ->toThrow(SaleAlreadyInvoicedException::class);

    app(CurrentTenant::class)->set($company->id);
    expect(Invoice::where('sale_id', $sale->id)->count())->toBe(1)
        ->and(Invoice::where('sale_id', $sale->id)->first()->id)->toBe($invoice->id);
});
