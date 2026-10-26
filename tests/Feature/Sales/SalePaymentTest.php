<?php

use App\Models\Client;
use App\Models\Company;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\Billing\SaleToInvoiceConverter;
use App\Support\Billing\SalePaymentRules;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.25: la venta guarda su forma y método de pago; la empresa sólo es
 * respaldo al convertir. PPD exige la forma 99 y PUE no la admite.
 */
/** @return array{Company, User, Client} */
function phase625Context(array $company = []): array
{
    $company = Company::factory()->create($company);
    $user = User::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create(['company_id' => $company->id]);

    return [$company, $user, $client];
}

function phase625ConfirmedSale(User $user, Client $client, Product $product): Sale
{
    $sale = test()->actingAs($user, 'api')->postJson('/api/sales', ['client_id' => $client->id])->json();
    test()->actingAs($user, 'api')->postJson("/api/sales/{$sale['id']}/items", ['product_id' => $product->id, 'quantity' => 1])->assertCreated();
    test()->actingAs($user, 'api')->postJson("/api/sales/{$sale['id']}/submit")->assertOk();
    test()->actingAs($user, 'api')->postJson("/api/sales/{$sale['id']}/confirm")->assertOk();
    app(CurrentTenant::class)->set($client->company_id);

    return Sale::findOrFail($sale['id']);
}

test('la migración agrega payment_form y payment_method nullable a sales', function () {
    expect(Schema::hasColumns('sales', ['payment_form', 'payment_method']))->toBeTrue();
});

test('crear una venta guarda su forma y método de pago', function (string $form, string $method) {
    [, $user, $client] = phase625Context();

    $this->actingAs($user, 'api')
        ->postJson('/api/sales', ['client_id' => $client->id, 'payment_form' => $form, 'payment_method' => $method])
        ->assertCreated()
        ->assertJsonPath('payment_form', $form)
        ->assertJsonPath('payment_method', $method);
})->with([
    'PUE transferencia' => ['03', 'PUE'],
    'PUE efectivo' => ['01', 'PUE'],
    'PPD por definir' => ['99', 'PPD'],
]);

test('rechaza combinaciones y formatos inválidos', function (array $payload, string $field, string $message) {
    [, $user, $client] = phase625Context();

    $this->actingAs($user, 'api')
        ->postJson('/api/sales', ['client_id' => $client->id, ...$payload])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field => $message]);
})->with([
    'PPD sin forma 99' => [['payment_form' => '03', 'payment_method' => 'PPD'], 'payment_form', 'Con método de pago PPD'],
    'PUE con forma 99' => [['payment_form' => '99', 'payment_method' => 'PUE'], 'payment_form', 'Con método de pago PUE'],
    'forma de 3 dígitos' => [['payment_form' => '003'], 'payment_form', '2 dígitos'],
    'método desconocido' => [['payment_method' => 'XYZ'], 'payment_method', 'PUE o PPD'],
]);

test('al editar, la regla se evalúa contra lo que la venta ya tenía', function () {
    [, $user, $client] = phase625Context();
    $sale = $this->actingAs($user, 'api')
        ->postJson('/api/sales', ['client_id' => $client->id, 'payment_form' => '99', 'payment_method' => 'PPD'])
        ->json();

    // Cambiar sólo el método a PUE dejaría la forma 99: incompatible.
    $this->actingAs($user, 'api')
        ->putJson("/api/sales/{$sale['id']}", ['payment_method' => 'PUE'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['payment_form' => 'Con método de pago PUE']);

    $this->actingAs($user, 'api')
        ->putJson("/api/sales/{$sale['id']}", ['payment_method' => 'PUE', 'payment_form' => '03'])
        ->assertOk()
        ->assertJsonPath('payment_form', '03')
        ->assertJsonPath('payment_method', 'PUE');
});

test('la venta sin forma ni método sigue siendo válida (se usará el respaldo)', function () {
    [, $user, $client] = phase625Context();

    $this->actingAs($user, 'api')
        ->postJson('/api/sales', ['client_id' => $client->id])
        ->assertCreated()
        ->assertJsonPath('payment_form', null)
        ->assertJsonPath('payment_method', null);
});

test('la conversión usa el pago de la venta y la empresa sólo como respaldo', function (?string $saleForm, ?string $saleMethod, ?string $companyForm, ?string $companyMethod, ?string $expectedForm, ?string $expectedMethod) {
    [$company, $user, $client] = phase625Context(['default_payment_form' => $companyForm, 'default_payment_method' => $companyMethod]);
    $product = Product::factory()->create(['company_id' => $company->id, 'clave_unidad' => 'H87']);
    $sale = phase625ConfirmedSale($user, $client, $product);
    $sale->forceFill(['payment_form' => $saleForm, 'payment_method' => $saleMethod])->save();
    app(CurrentTenant::class)->set($company->id);

    $invoice = app(SaleToInvoiceConverter::class)->convert($sale->fresh(), $user->id);

    expect($invoice->payment_form)->toBe($expectedForm)
        ->and($invoice->payment_method)->toBe($expectedMethod);
})->with([
    'valor de la venta' => ['99', 'PPD', '03', 'PUE', '99', 'PPD'],
    'respaldo de la empresa' => [null, null, '03', 'PUE', '03', 'PUE'],
    'mezcla: forma de la venta, método de la empresa' => ['01', null, '03', 'PUE', '01', 'PUE'],
    'ninguno' => [null, null, null, null, null, null],
]);

test('SalePaymentRules: violación y valor efectivo', function () {
    expect(SalePaymentRules::violation('03', 'PPD'))->toBe(SalePaymentRules::PPD_REQUIRES_FORM_99)
        ->and(SalePaymentRules::violation('99', 'PUE'))->toBe(SalePaymentRules::PUE_FORBIDS_FORM_99)
        ->and(SalePaymentRules::violation('99', 'PPD'))->toBeNull()
        ->and(SalePaymentRules::violation(null, 'PPD'))->toBeNull()
        ->and(SalePaymentRules::effective(null, 'PPD', '03', 'PUE'))->toBe(['03', 'PPD']);
});
