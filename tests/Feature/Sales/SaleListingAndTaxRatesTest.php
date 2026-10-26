<?php

use App\Enums\SaleStatus;
use App\Enums\TaxFactorType;
use App\Enums\TaxType;
use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Sale;
use App\Models\TaxRate;
use App\Models\User;

/**
 * Fase 6.25: GET /api/sales paginado con filtros, SaleResource explícito
 * (cliente y factura vigente resumidos) y GET /api/tax-rates con sólo los
 * traslados activos.
 */
test('GET /api/sales pagina, filtra por estado y busca por folio o cliente', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $acme = Client::factory()->create(['company_id' => $company->id, 'name' => 'Acme Industrial', 'rfc' => 'AIN010101AAA']);
    $other = Client::factory()->create(['company_id' => $company->id, 'name' => 'Otra Empresa']);
    Sale::factory()->count(3)->create(['company_id' => $company->id, 'client_id' => $other->id]);
    $acmeSale = Sale::factory()->create(['company_id' => $company->id, 'client_id' => $acme->id, 'status' => SaleStatus::Confirmed]);

    $page = $this->actingAs($user, 'api')->getJson('/api/sales?per_page=10')->assertOk();
    expect($page->json('meta.total'))->toBe(4)
        ->and($page->json('data'))->toHaveCount(4)
        ->and($page->json('data.0'))->not->toHaveKey('items');

    $this->actingAs($user, 'api')->getJson('/api/sales?status=confirmed')
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $acmeSale->id);

    foreach (['Acme', 'AIN010101', $acmeSale->folio] as $search) {
        $this->actingAs($user, 'api')->getJson('/api/sales?search='.urlencode($search))
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.client.name', 'Acme Industrial');
    }
});

test('la búsqueda por cliente no se escapa a ventas de otras empresas', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $otherCompany = Company::factory()->create();
    $foreign = Client::factory()->create(['company_id' => $otherCompany->id, 'name' => 'Acme Externa']);
    Sale::factory()->create(['company_id' => $otherCompany->id, 'client_id' => $foreign->id]);
    Sale::factory()->create(['company_id' => $company->id]);

    $this->actingAs($user, 'api')->getJson('/api/sales?search=Acme')->assertOk()->assertJsonPath('meta.total', 0);
});

test('GET /api/sales rechaza filtros inválidos', function (string $query) {
    $user = User::factory()->create(['company_id' => Company::factory()->create()->id]);

    $this->actingAs($user, 'api')->getJson('/api/sales?'.$query)->assertUnprocessable();
})->with(['status=pagada', 'per_page=7', 'page=0']);

test('cada venta expone su factura vigente resumida (o null) y nunca deleted_at', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $sale = Sale::factory()->create(['company_id' => $company->id, 'status' => SaleStatus::Confirmed]);
    $withoutInvoice = Sale::factory()->create(['company_id' => $company->id]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'sale_id' => $sale->id]);

    $this->actingAs($user, 'api')->getJson("/api/sales/{$sale->id}")
        ->assertOk()
        ->assertJsonPath('invoice.id', $invoice->id)
        ->assertJsonPath('invoice.folio', $invoice->folio)
        ->assertJsonPath('invoice.status', $invoice->status->value)
        ->assertJsonMissingPath('deleted_at')
        ->assertJsonStructure(['id', 'folio', 'status', 'payment_form', 'payment_method', 'client' => ['id', 'name', 'rfc'], 'items']);

    $this->actingAs($user, 'api')->getJson("/api/sales/{$withoutInvoice->id}")
        ->assertOk()->assertJsonPath('invoice', null);
});

test('GET /api/tax-rates devuelve sólo traslados activos', function () {
    $user = User::factory()->create(['company_id' => Company::factory()->create()->id]);
    $iva = TaxRate::factory()->create(['name' => 'IVA 16%', 'rate' => 0.16, 'tax_type' => TaxType::Traslado, 'factor_type' => TaxFactorType::Tasa, 'active' => true]);
    TaxRate::factory()->create(['name' => 'ISR Retenido', 'rate' => 0.10, 'tax_type' => TaxType::Retencion, 'factor_type' => TaxFactorType::Tasa, 'active' => true]);
    TaxRate::factory()->create(['name' => 'IVA viejo', 'rate' => 0.15, 'tax_type' => TaxType::Traslado, 'factor_type' => TaxFactorType::Tasa, 'active' => false]);

    $names = collect($this->actingAs($user, 'api')->getJson('/api/tax-rates')->assertOk()->json())->pluck('name');

    expect($names->all())->toContain('IVA 16%')
        ->not->toContain('ISR Retenido')
        ->not->toContain('IVA viejo');
    $this->actingAs($user, 'api')->getJson('/api/tax-rates')
        ->assertJsonFragment(['id' => $iva->id, 'tax_type' => 'traslado', 'factor_type' => 'tasa']);
});

test('GET /api/tax-rates exige sesión', function () {
    $this->getJson('/api/tax-rates')->assertUnauthorized();
});
