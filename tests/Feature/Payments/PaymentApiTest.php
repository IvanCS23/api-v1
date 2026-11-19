<?php

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;

/**
 * Fase 6.35 (2): API de registro de pagos (borradores) y facturas por cobrar.
 */
function p635User(Company $company, UserRole $role = UserRole::Accountant): User
{
    return User::factory()->create(['company_id' => $company->id, 'role' => $role]);
}

function p635Invoice(Company $company, Client $client, string $total = '1160', array $attributes = []): Invoice
{
    return Invoice::factory()->create([
        'company_id' => $company->id,
        'client_id' => $client->id,
        'status' => 'issued',
        'payment_method' => 'PPD',
        'payment_form' => '99',
        'currency' => 'MXN',
        'total' => $total,
        'cfdi_uuid' => fake()->uuid(),
        'issued_at' => now()->subDays(3),
        ...$attributes,
    ]);
}

function p635Payload(Client $client, array $allocations, string $amount, array $extra = []): array
{
    return [
        'client_id' => $client->id,
        'payment_date' => now()->subDay()->toDateTimeString(),
        'payment_form' => '03',
        'amount' => $amount,
        'operation_number' => 'SPEI-123',
        'allocations' => $allocations,
        ...$extra,
    ];
}

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->client = Client::factory()->create(['company_id' => $this->company->id]);
    $this->user = p635User($this->company);
});

test('registra un pago en borrador con folio PAG y lo reparte entre facturas', function () {
    $first = p635Invoice($this->company, $this->client, '1160');
    $second = p635Invoice($this->company, $this->client, '580');

    $response = $this->actingAs($this->user, 'api')->postJson('/api/payments', p635Payload($this->client, [
        ['invoice_id' => $first->id, 'amount' => '500'],
        ['invoice_id' => $second->id, 'amount' => '300.50'],
    ], '800.50'))->assertCreated();

    $response->assertJsonPath('folio', 'PAG-00000001')
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('currency', 'MXN')
        ->assertJsonPath('amount', '800.500000')
        ->assertJsonPath('created_by', $this->user->id)
        ->assertJsonCount(2, 'allocations')
        ->assertJsonPath('allocations.0.invoice_folio', $first->folio)
        ->assertJsonPath('allocations.0.installment', null)
        ->assertJsonMissingPath('pac_response');

    expect(Payment::withoutGlobalScopes()->count())->toBe(1)
        ->and(PaymentAllocation::withoutGlobalScopes()->sum('amount'))->toEqual(800.5);
});

test('facturas por cobrar: sólo PPD timbradas en MXN del cliente, con saldo y lo reservado', function () {
    $ppd = p635Invoice($this->company, $this->client, '1160');
    p635Invoice($this->company, $this->client, '100', ['payment_method' => 'PUE']);
    p635Invoice($this->company, $this->client, '100', ['status' => 'draft', 'cfdi_uuid' => null]);
    p635Invoice($this->company, $this->client, '100', ['cancellation_status' => 'pending']);
    p635Invoice($this->company, $this->client, '100', ['currency' => 'USD']);
    p635Invoice($this->company, Client::factory()->create(['company_id' => $this->company->id]));
    $paid = p635Invoice($this->company, $this->client, '200');

    $issued = Payment::factory()->issued()->create(['company_id' => $this->company->id, 'client_id' => $this->client->id, 'amount' => 200]);
    PaymentAllocation::factory()->create(['company_id' => $this->company->id, 'payment_id' => $issued->id, 'invoice_id' => $paid->id, 'amount' => 200]);
    $draft = Payment::factory()->create(['company_id' => $this->company->id, 'client_id' => $this->client->id, 'amount' => 160]);
    PaymentAllocation::factory()->create(['company_id' => $this->company->id, 'payment_id' => $draft->id, 'invoice_id' => $ppd->id, 'amount' => 160]);

    $rows = $this->actingAs($this->user, 'api')
        ->getJson("/api/payments/receivable-invoices?client_id={$this->client->id}")
        ->assertOk()->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['id'])->toBe($ppd->id)
        ->and($rows[0]['balance'])->toBe('1160.000000')
        ->and($rows[0]['available'])->toBe('1000.000000')
        ->and($rows[0]['issued_payments'])->toBe(0);

    $editing = $this->actingAs($this->user, 'api')
        ->getJson("/api/payments/receivable-invoices?client_id={$this->client->id}&payment_id={$draft->id}")
        ->json('data');
    expect($editing[0]['available'])->toBe('1160.000000');
});

test('rechaza facturas no PPD, de otro cliente o sin timbrar', function (array $attributes, bool $otherClient) {
    $client = $otherClient ? Client::factory()->create(['company_id' => $this->company->id]) : $this->client;
    $invoice = p635Invoice($this->company, $client, '1160', $attributes);

    $this->actingAs($this->user, 'api')->postJson('/api/payments', p635Payload($this->client, [
        ['invoice_id' => $invoice->id, 'amount' => '100'],
    ], '100'))->assertStatus(422)->assertJsonValidationErrors(['allocations.0.invoice_id']);

    expect(Payment::withoutGlobalScopes()->count())->toBe(0);
})->with([
    'PUE' => [['payment_method' => 'PUE'], false],
    'otro cliente' => [[], true],
    'sin timbrar' => [['status' => 'draft', 'cfdi_uuid' => null], false],
    'cancelación aceptada' => [['cancellation_status' => 'accepted'], false],
]);

test('rechaza facturas de otra empresa', function () {
    $other = Company::factory()->create();
    $invoice = p635Invoice($other, Client::factory()->create(['company_id' => $other->id]));

    $this->actingAs($this->user, 'api')->postJson('/api/payments', p635Payload($this->client, [
        ['invoice_id' => $invoice->id, 'amount' => '100'],
    ], '100'))->assertStatus(422)->assertJsonValidationErrors(['allocations.0.invoice_id']);
});

test('el monto aplicado no rebasa el saldo disponible (incluye lo reservado por otros borradores)', function () {
    $invoice = p635Invoice($this->company, $this->client, '1160');
    $this->actingAs($this->user, 'api')->postJson('/api/payments', p635Payload($this->client, [
        ['invoice_id' => $invoice->id, 'amount' => '1000'],
    ], '1000'))->assertCreated();

    $this->actingAs($this->user, 'api')->postJson('/api/payments', p635Payload($this->client, [
        ['invoice_id' => $invoice->id, 'amount' => '160.01'],
    ], '160.01'))->assertStatus(422)->assertJsonValidationErrors(['allocations.0.amount']);

    $this->actingAs($this->user, 'api')->postJson('/api/payments', p635Payload($this->client, [
        ['invoice_id' => $invoice->id, 'amount' => '160'],
    ], '160'))->assertCreated();
});

test('la suma de lo aplicado debe ser igual al monto del pago', function () {
    $invoice = p635Invoice($this->company, $this->client, '1160');

    $this->actingAs($this->user, 'api')->postJson('/api/payments', p635Payload($this->client, [
        ['invoice_id' => $invoice->id, 'amount' => '400'],
    ], '500'))->assertStatus(422)->assertJsonValidationErrors(['allocations']);
});

test('validaciones del payload: fecha futura, forma 99, moneda, montos y facturas repetidas', function () {
    $invoice = p635Invoice($this->company, $this->client, '1160');
    $allocations = [['invoice_id' => $invoice->id, 'amount' => '100']];

    $this->actingAs($this->user, 'api')->postJson('/api/payments', p635Payload($this->client, $allocations, '100', [
        'payment_date' => now()->addDay()->toDateTimeString(),
        'payment_form' => '99',
        'currency' => 'USD',
    ]))->assertStatus(422)->assertJsonValidationErrors(['payment_date', 'payment_form', 'currency']);

    $this->actingAs($this->user, 'api')->postJson('/api/payments', p635Payload($this->client, [
        ['invoice_id' => $invoice->id, 'amount' => '0'],
        ['invoice_id' => $invoice->id, 'amount' => '10.123'],
    ], '0.00'))->assertStatus(422)->assertJsonValidationErrors([
        'amount', 'allocations.0.amount', 'allocations.1.amount', 'allocations.0.invoice_id',
    ]);

    $this->actingAs($this->user, 'api')->postJson('/api/payments', p635Payload($this->client, [], '100'))
        ->assertStatus(422)->assertJsonValidationErrors(['allocations']);
});

test('D5: sólo Admin y Contador manejan pagos', function (UserRole $role, bool $allowed) {
    $user = p635User($this->company, $role);
    $invoice = p635Invoice($this->company, $this->client);

    $index = $this->actingAs($user, 'api')->getJson('/api/payments');
    $store = $this->actingAs($user, 'api')->postJson('/api/payments', p635Payload($this->client, [
        ['invoice_id' => $invoice->id, 'amount' => '100'],
    ], '100'));

    expect($index->status())->toBe($allowed ? 200 : 403)
        ->and($store->status())->toBe($allowed ? 201 : 403);
})->with([
    'admin' => [UserRole::Admin, true],
    'contador' => [UserRole::Accountant, true],
    'ventas' => [UserRole::Sales, false],
    'empleado' => [UserRole::Employee, false],
]);

test('un pago de otra empresa no se ve ni se edita', function () {
    $other = Company::factory()->create();
    $payment = Payment::factory()->create(['company_id' => $other->id]);

    $this->actingAs($this->user, 'api')->getJson("/api/payments/{$payment->id}")->assertNotFound();
    $this->actingAs($this->user, 'api')->deleteJson("/api/payments/{$payment->id}")->assertNotFound();
    $this->actingAs($this->user, 'api')->getJson('/api/payments')->assertOk()->assertJsonCount(0, 'data');
});

test('un borrador se edita (reemplaza el reparto) y se elimina liberando el saldo', function () {
    $first = p635Invoice($this->company, $this->client, '1160');
    $second = p635Invoice($this->company, $this->client, '580');
    $id = $this->actingAs($this->user, 'api')->postJson('/api/payments', p635Payload($this->client, [
        ['invoice_id' => $first->id, 'amount' => '1160'],
    ], '1160'))->assertCreated()->json('id');

    // Editar puede volver a usar el saldo que el propio borrador reservaba.
    $this->actingAs($this->user, 'api')->putJson("/api/payments/{$id}", p635Payload($this->client, [
        ['invoice_id' => $first->id, 'amount' => '1160'],
        ['invoice_id' => $second->id, 'amount' => '40'],
    ], '1200', ['payment_form' => '02']))->assertOk()
        ->assertJsonPath('payment_form', '02')
        ->assertJsonPath('amount', '1200.000000')
        ->assertJsonCount(2, 'allocations');

    expect(PaymentAllocation::withoutGlobalScopes()->where('payment_id', $id)->count())->toBe(2);

    $this->actingAs($this->user, 'api')->deleteJson("/api/payments/{$id}")->assertNoContent();

    $rows = $this->actingAs($this->user, 'api')
        ->getJson("/api/payments/receivable-invoices?client_id={$this->client->id}")->json('data');
    expect(collect($rows)->pluck('available', 'id')->all())->toBe([
        $first->id => '1160.000000',
        $second->id => '580.000000',
    ]);
});

test('un pago con REP emitido no se edita ni se elimina', function () {
    $payment = Payment::factory()->issued()->create(['company_id' => $this->company->id, 'client_id' => $this->client->id]);
    $invoice = p635Invoice($this->company, $this->client);

    $this->actingAs($this->user, 'api')->putJson("/api/payments/{$payment->id}", p635Payload($this->client, [
        ['invoice_id' => $invoice->id, 'amount' => '100'],
    ], '100'))->assertStatus(409)->assertJsonPath('code', 'PAYMENT_NOT_DRAFT');

    $this->actingAs($this->user, 'api')->deleteJson("/api/payments/{$payment->id}")
        ->assertStatus(409)->assertJsonPath('code', 'PAYMENT_NOT_DRAFT');

    expect(Payment::withoutGlobalScopes()->whereKey($payment->id)->exists())->toBeTrue();
});

test('listado con filtros por estado y búsqueda', function () {
    Payment::factory()->create(['company_id' => $this->company->id, 'client_id' => $this->client->id, 'folio' => 'PAG-00000007']);
    Payment::factory()->issued()->create(['company_id' => $this->company->id, 'client_id' => $this->client->id, 'folio' => 'PAG-00000008']);

    $this->actingAs($this->user, 'api')->getJson('/api/payments?status=issued')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.folio', 'PAG-00000008');
    $this->actingAs($this->user, 'api')->getJson('/api/payments?search=00000007')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.client.id', $this->client->id);
});
