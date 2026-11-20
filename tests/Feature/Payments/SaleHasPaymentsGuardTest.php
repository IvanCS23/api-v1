<?php

use App\Exceptions\SaleNotBillableException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Sale;
use App\Services\Billing\SaleBillingReadinessService;
use App\Services\Payments\SalePayments;
use App\Services\Taxes\SaleTaxRecalculator;
use App\Support\Tenant\CurrentTenant;

/**
 * Fase 6.35 (D8): una venta cuya factura (incluso borrada) tiene pagos no
 * cancelados no se recalcula ni se factura. Se simula ese estado de los
 * datos, que el flujo normal no produce.
 */
beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->sale = Sale::factory()->create(['company_id' => $this->company->id]);
    $this->invoice = Invoice::factory()->create(['company_id' => $this->company->id, 'sale_id' => $this->sale->id, 'client_id' => $this->sale->client_id]);
    app(CurrentTenant::class)->set($this->company->id);
});

function p635SalePayment(object $test, string $status): void
{
    $payment = Payment::factory()->create(['company_id' => $test->company->id, 'client_id' => $test->sale->client_id, 'status' => $status]);
    PaymentAllocation::factory()->create(['company_id' => $test->company->id, 'payment_id' => $payment->id, 'invoice_id' => $test->invoice->id]);
}

test('pago en borrador o emitido sobre una factura borrada de la venta: SALE_HAS_PAYMENTS', function (string $status) {
    p635SalePayment($this, $status);
    $this->invoice->delete();

    $errors = collect(app(SaleBillingReadinessService::class)->evaluate($this->sale->fresh())['errors'])->pluck('code');

    expect(SalePayments::exist($this->sale))->toBeTrue()
        ->and($errors)->toContain('SALE_HAS_PAYMENTS');

    try {
        (new SaleTaxRecalculator)->apply($this->sale->fresh());
        $this->fail('El recálculo debía negarse.');
    } catch (SaleNotBillableException $e) {
        expect($e->errors[0]['code'])->toBe('SALE_HAS_PAYMENTS');
    }
})->with(['draft', 'issued']);

test('sin pagos, o con el pago cancelado, la guarda no aplica', function () {
    expect(SalePayments::exist($this->sale))->toBeFalse();

    p635SalePayment($this, 'cancelled');

    expect(SalePayments::exist($this->sale))->toBeFalse()
        ->and(collect(app(SaleBillingReadinessService::class)->evaluate($this->sale->fresh())['errors'])->pluck('code'))->not->toContain('SALE_HAS_PAYMENTS');
});

test('los pagos de otra venta no cuentan', function () {
    $other = Invoice::factory()->create(['company_id' => $this->company->id]);
    $payment = Payment::factory()->create(['company_id' => $this->company->id, 'client_id' => $other->client_id]);
    PaymentAllocation::factory()->create(['company_id' => $this->company->id, 'payment_id' => $payment->id, 'invoice_id' => $other->id]);

    expect(SalePayments::exist($this->sale))->toBeFalse();
});
