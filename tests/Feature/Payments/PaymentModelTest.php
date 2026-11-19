<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentAllocationTax;
use App\Services\Payments\InvoiceBalance;
use App\Services\Payments\PaymentNumberGenerator;
use App\Support\Tenant\CurrentTenant;

/**
 * Fase 6.35 (1): modelo de pagos y saldo de la factura PPD.
 */
function phase635Invoice(Company $company, string $total = '1160'): Invoice
{
    return Invoice::factory()->create(['company_id' => $company->id, 'status' => 'issued', 'payment_method' => 'PPD', 'payment_form' => '99', 'total' => $total]);
}

function phase635Allocate(Company $company, Invoice $invoice, string $amount, array $payment = []): PaymentAllocation
{
    $model = Payment::factory()->create(['company_id' => $company->id, 'client_id' => $invoice->client_id, ...$payment]);

    return PaymentAllocation::factory()->create(['company_id' => $company->id, 'payment_id' => $model->id, 'invoice_id' => $invoice->id, 'amount' => $amount]);
}

test('folios PAG consecutivos por empresa', function () {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $generator = new PaymentNumberGenerator;

    expect($generator->next($a->id))->toBe('PAG-00000001');
    Payment::factory()->create(['company_id' => $a->id, 'folio' => 'PAG-00000001']);
    expect($generator->next($a->id))->toBe('PAG-00000002')
        ->and($generator->next($b->id))->toBe('PAG-00000001')
        ->and(PaymentNumberGenerator::number('PAG-00000012'))->toBe(12);
});

test('saldo: sólo los REP emitidos lo reducen; los borradores sólo reservan', function () {
    $company = Company::factory()->create();
    $invoice = phase635Invoice($company);
    $balance = new InvoiceBalance;

    phase635Allocate($company, $invoice, '500', ['status' => 'issued']);
    $draft = phase635Allocate($company, $invoice, '200');

    expect($balance->fiscal($invoice))->toBe('660.000000')
        ->and($balance->available($invoice))->toBe('460.000000')
        ->and($balance->available($invoice, $draft->payment_id))->toBe('660.000000')
        ->and($balance->issuedCount($invoice))->toBe(1);
});

test('ajuste 1: la cancelación pendiente o rechazada no devuelve el saldo; la aceptada sí', function () {
    $company = Company::factory()->create();
    $invoice = phase635Invoice($company);
    $balance = new InvoiceBalance;

    phase635Allocate($company, $invoice, '100', ['status' => 'issued', 'cancellation_status' => 'pending']);
    phase635Allocate($company, $invoice, '100', ['status' => 'issued', 'cancellation_status' => 'rejected']);
    phase635Allocate($company, $invoice, '100', ['status' => 'cancelled', 'cancellation_status' => 'accepted']);

    expect($balance->fiscal($invoice))->toBe('960.000000')
        ->and($balance->hasActivePayments($invoice))->toBeTrue();
});

test('un pago puede aplicarse a varias facturas del mismo cliente', function () {
    $company = Company::factory()->create();
    $first = phase635Invoice($company, '1160');
    $second = Invoice::factory()->create(['company_id' => $company->id, 'client_id' => $first->client_id, 'status' => 'issued', 'payment_method' => 'PPD', 'total' => '580']);
    $payment = Payment::factory()->create(['company_id' => $company->id, 'client_id' => $first->client_id, 'status' => 'issued', 'amount' => '800']);
    PaymentAllocation::factory()->create(['company_id' => $company->id, 'payment_id' => $payment->id, 'invoice_id' => $first->id, 'amount' => '500']);
    PaymentAllocation::factory()->create(['company_id' => $company->id, 'payment_id' => $payment->id, 'invoice_id' => $second->id, 'amount' => '300']);
    app(CurrentTenant::class)->set($company->id);

    expect($payment->fresh()->allocations)->toHaveCount(2)
        ->and((new InvoiceBalance)->fiscal($first))->toBe('660.000000')
        ->and((new InvoiceBalance)->fiscal($second))->toBe('280.000000')
        ->and($first->fresh()->paymentAllocations)->toHaveCount(1);
});

test('los impuestos de la asignación viajan con ella', function () {
    $company = Company::factory()->create();
    $allocation = phase635Allocate($company, phase635Invoice($company), '116');
    $tax = new PaymentAllocationTax(['payment_allocation_id' => $allocation->id, 'tax' => '002', 'kind' => 'traslado', 'factor' => 'tasa', 'rate' => 0.16, 'base' => 100, 'amount' => 16, 'amount_rounded' => 16]);
    $tax->company_id = $company->id;
    $tax->save();

    expect($allocation->fresh()->taxes)->toHaveCount(1)
        ->and($allocation->fresh()->taxes->first()->amount)->toBe('16.000000');
});

test('los pagos están aislados por empresa', function () {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    Payment::factory()->create(['company_id' => $a->id]);

    app(CurrentTenant::class)->set($b->id);
    expect(Payment::count())->toBe(0);
    app(CurrentTenant::class)->set($a->id);
    expect(Payment::count())->toBe(1);
});
