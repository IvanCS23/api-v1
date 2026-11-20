<?php

use App\Enums\CfdiCancellationMotive;
use App\Enums\UserRole;
use App\Exceptions\InvoiceHasActivePaymentsException;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\Billing\InvoiceBusinessCapabilitiesService;
use App\Services\Billing\OrchestrateInvoiceCancellationService;
use App\Services\Payments\InvoiceBalance;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Fase 6.35 (5): cancelación del REP (ajustes 1 y 2) y bloqueo de la
 * cancelación de una factura PPD con REP vigentes (ajuste 4).
 */
beforeEach(function () {
    facturapiOrganizationsFromConfigKey();
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_PHASE_635_CANCEL',
    ]);

    $this->company = Company::factory()->create();
    $this->client = Client::factory()->create(['company_id' => $this->company->id]);
    $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => UserRole::Admin]);
    $this->invoice = Invoice::factory()->create([
        'company_id' => $this->company->id,
        'client_id' => $this->client->id,
        'status' => 'issued',
        'payment_method' => 'PPD',
        'payment_form' => '99',
        'total' => '1160',
        'cfdi_uuid' => 'aaaaaaaa-1111-2222-3333-444444444444',
        'pac_external_id' => 'inv_ppd_1',
        'pac_status' => 'valid',
        'pac_environment' => 'test',
        'stamped_at' => now()->subDay(),
    ]);
    $this->payment = Payment::factory()->issued()->create([
        'company_id' => $this->company->id,
        'client_id' => $this->client->id,
        'amount' => '580',
        'pac_external_id' => 'rep_1',
        'pac_environment' => 'test',
    ]);
    PaymentAllocation::factory()->create(['company_id' => $this->company->id, 'payment_id' => $this->payment->id, 'invoice_id' => $this->invoice->id, 'amount' => '580', 'installment' => 1]);
});

/** @param  array<int, array<string, mixed>|Throwable>  $responses  respuestas en orden */
function p635FakeCancel(array $responses, array &$calls = []): void
{
    Http::fake(function (Request $request) use (&$responses, &$calls) {
        $calls[] = $request->method().' '.$request->url();
        $next = array_shift($responses);
        if ($next instanceof Throwable) {
            throw $next;
        }

        return Http::response(['id' => 'rep_1', 'livemode' => false, ...$next]);
    });
}

function p635Fresh(Payment $payment): Payment
{
    return Payment::withoutGlobalScopes()->find($payment->id);
}

test('ajuste 1: la solicitud queda pendiente y el pago sigue contando; al aceptarse vuelve el saldo', function () {
    $calls = [];
    p635FakeCancel([
        ['status' => 'valid', 'cancellation_status' => 'pending'],
        ['status' => 'canceled', 'cancellation_status' => 'accepted'],
    ], $calls);
    $balance = new InvoiceBalance;

    $this->actingAs($this->user, 'api')->postJson("/api/payments/{$this->payment->id}/cancel", ['motive' => '02'])
        ->assertOk()->assertJsonPath('status', 'issued')->assertJsonPath('cancellation_status', 'pending')->assertJsonPath('cancellation_motive', '02');

    expect($calls[0])->toBe('DELETE https://example-pac.test/v2/invoices/rep_1?motive=02')
        ->and($balance->fiscal($this->invoice))->toBe('580.000000')
        ->and($balance->hasActivePayments($this->invoice))->toBeTrue()
        ->and(AuditLog::where('action', 'payment_cancellation_requested')->first()->metadata['motive'])->toBe('02');

    // Pendiente: no se vuelve a pedir.
    $this->actingAs($this->user, 'api')->postJson("/api/payments/{$this->payment->id}/cancel", ['motive' => '02'])->assertOk();
    expect($calls)->toHaveCount(1);

    $this->actingAs($this->user, 'api')->postJson("/api/payments/{$this->payment->id}/sync")
        ->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('cancellation_status', 'accepted');

    expect($calls[1])->toBe('GET https://example-pac.test/v2/invoices/rep_1')
        ->and($balance->fiscal($this->invoice))->toBe('1160.000000')
        ->and($balance->hasActivePayments($this->invoice))->toBeFalse()
        ->and(p635Fresh($this->payment)->cancelled_at)->not->toBeNull();
});

test('ajuste 1: cancelación rechazada por el receptor, el pago sigue contando y se puede volver a pedir', function () {
    p635FakeCancel([
        ['status' => 'valid', 'cancellation_status' => 'pending'],
        ['status' => 'valid', 'cancellation_status' => 'rejected'],
        ['status' => 'valid', 'cancellation_status' => 'pending'],
    ]);

    $this->actingAs($this->user, 'api')->postJson("/api/payments/{$this->payment->id}/cancel", ['motive' => '03'])->assertOk();
    $this->actingAs($this->user, 'api')->postJson("/api/payments/{$this->payment->id}/sync")
        ->assertOk()->assertJsonPath('status', 'issued')->assertJsonPath('cancellation_status', 'rejected');

    expect((new InvoiceBalance)->fiscal($this->invoice))->toBe('580.000000');

    $this->actingAs($this->user, 'api')->postJson("/api/payments/{$this->payment->id}/cancel", ['motive' => '03'])
        ->assertOk()->assertJsonPath('cancellation_status', 'pending');
});

test('ajuste 2: sólo motivos 02 y 03', function (string $motive) {
    Http::fake();

    $this->actingAs($this->user, 'api')->postJson("/api/payments/{$this->payment->id}/cancel", ['motive' => $motive])
        ->assertStatus(422)->assertJsonValidationErrors(['motive']);

    Http::assertNothingSent();
})->with(['01', '04', '99']);

test('un pago en borrador no se cancela', function () {
    Http::fake();
    $draft = Payment::factory()->create(['company_id' => $this->company->id, 'client_id' => $this->client->id]);

    $this->actingAs($this->user, 'api')->postJson("/api/payments/{$draft->id}/cancel", ['motive' => '02'])
        ->assertStatus(409)->assertJsonPath('code', 'PAYMENT_NOT_ISSUED');

    Http::assertNothingSent();
});

test('respuesta ambigua del DELETE: queda unknown, bloquea otro intento y sync la resuelve', function () {
    $calls = [];
    p635FakeCancel([
        new ConnectionException('timeout'),
        ['status' => 'valid', 'cancellation_status' => 'none'],
    ], $calls);

    $this->actingAs($this->user, 'api')->postJson("/api/payments/{$this->payment->id}/cancel", ['motive' => '02'])->assertStatus(503);
    expect(p635Fresh($this->payment)->cancellation_status)->toBe('unknown');

    $this->actingAs($this->user, 'api')->postJson("/api/payments/{$this->payment->id}/cancel", ['motive' => '02'])
        ->assertStatus(409)->assertJsonPath('code', 'PAYMENT_CANCELLATION_IN_PROGRESS');

    $this->actingAs($this->user, 'api')->postJson("/api/payments/{$this->payment->id}/sync")
        ->assertOk()->assertJsonPath('status', 'issued')->assertJsonPath('cancellation_status', null);

    expect($calls)->toHaveCount(2);
});

test('un rechazo claro del PAC deja el REP como estaba', function () {
    Http::fake(['*' => Http::response(['message' => 'No cancelable', 'code' => 'validation'], 400)]);

    $this->actingAs($this->user, 'api')->postJson("/api/payments/{$this->payment->id}/cancel", ['motive' => '02'])->assertStatus(422);

    $fresh = p635Fresh($this->payment);
    expect($fresh->status->value)->toBe('issued')
        ->and($fresh->cancellation_status)->toBeNull()
        ->and($fresh->pac_last_error)->toContain('No cancelable');
});

test('ajuste 4: una factura PPD con REP vigentes no se cancela ni ofrece cancelar', function () {
    Http::fake();
    app(CurrentTenant::class)->set($this->company->id);

    expect(fn () => app(OrchestrateInvoiceCancellationService::class)->cancel($this->invoice, CfdiCancellationMotive::ErrorsWithoutRelation))
        ->toThrow(InvoiceHasActivePaymentsException::class);

    $this->actingAs($this->user, 'api')->postJson("/api/invoices/{$this->invoice->id}/operations/cancel", ['motive' => '02', 'confirm' => true])
        ->assertStatus(409)->assertJsonPath('code', 'INVOICE_HAS_ACTIVE_PAYMENTS');

    expect(app(InvoiceBusinessCapabilitiesService::class)->for($this->invoice->fresh(), $this->user)['can_cancel'])->toBeFalse();

    // Con la cancelación pendiente el REP sigue vigente (ajuste 1).
    $this->payment->forceFill(['cancellation_status' => 'pending'])->save();
    expect(app(InvoiceBusinessCapabilitiesService::class)->for($this->invoice->fresh(), $this->user)['can_cancel'])->toBeFalse();

    // Cancelación aceptada: la factura vuelve a ofrecer cancelar.
    $this->payment->forceFill(['status' => 'cancelled', 'cancellation_status' => 'accepted'])->save();
    expect(app(InvoiceBusinessCapabilitiesService::class)->for($this->invoice->fresh(), $this->user)['can_cancel'])->toBeTrue();

    Http::assertNothingSent();
});
