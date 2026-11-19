<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceItemTax;
use App\Models\PaymentAllocation;
use App\Models\PaymentAllocationTax;
use App\Models\Scopes\CompanyScope;
use App\Services\Payments\PaymentTaxProrater;

/**
 * Fase 6.35 (3): impuestos del documento relacionado prorrateados al pago.
 * El redondeo de BaseDR/ImporteDR sigue por confirmar (ajuste 5).
 */
function p635TaxInvoice(Company $company, string $total, array $lines): Invoice
{
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => 'issued', 'payment_method' => 'PPD', 'total' => $total]);

    foreach ($lines as [$subtotal, $taxTotal, $taxes]) {
        $item = InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id, 'subtotal' => $subtotal, 'tax_total' => $taxTotal]);
        foreach ($taxes as $position => $row) {
            $tax = new InvoiceItemTax([...$row, 'invoice_item_id' => $item->id, 'position' => $position]);
            $tax->company_id = $company->id;
            $tax->save();
        }
    }

    return $invoice;
}

function p635Iva(string $base, string $amount): array
{
    return ['tax' => '002', 'kind' => 'traslado', 'factor' => 'tasa', 'rate' => '0.160000', 'base' => $base, 'amount' => $amount, 'amount_rounded' => round((float) $amount, 2)];
}

test('IVA 16 %: base e importe proporcionales al pago', function () {
    $company = Company::factory()->create();
    $invoice = p635TaxInvoice($company, '1160', [['1000', '160', [p635Iva('1000', '160')]]]);

    $result = (new PaymentTaxProrater)->prorate($invoice, '580');

    expect($result['taxability'])->toBe('02')
        ->and($result['taxes'])->toHaveCount(1)
        ->and($result['taxes'][0])->toMatchArray([
            'tax' => '002', 'kind' => 'traslado', 'factor' => 'tasa', 'rate' => '0.160000',
            'base' => '500.000000', 'amount' => '80.000000', 'amount_rounded' => '80.00',
        ]);
});

test('agrupa por impuesto y tasa entre líneas; retenciones y exentos', function () {
    $company = Company::factory()->create();
    $invoice = p635TaxInvoice($company, '953.33', [
        ['600', '96', [
            p635Iva('600', '96'),
            ['tax' => '001', 'kind' => 'retencion', 'factor' => 'tasa', 'rate' => '0.100000', 'base' => '600', 'amount' => '60', 'amount_rounded' => 60],
            ['tax' => '002', 'kind' => 'retencion', 'factor' => 'tasa', 'rate' => '0.106667', 'base' => '600', 'amount' => '64.0002', 'amount_rounded' => 64],
        ]],
        ['400', '64', [
            p635Iva('400', '64'),
            ['tax' => '001', 'kind' => 'retencion', 'factor' => 'tasa', 'rate' => '0.100000', 'base' => '400', 'amount' => '40', 'amount_rounded' => 40],
            ['tax' => '002', 'kind' => 'retencion', 'factor' => 'tasa', 'rate' => '0.106667', 'base' => '400', 'amount' => '42.6668', 'amount_rounded' => 42.67],
        ]],
        ['50', '0', [['tax' => '002', 'kind' => 'traslado', 'factor' => 'exento', 'rate' => null, 'base' => '50', 'amount' => null, 'amount_rounded' => 0]]],
    ]);

    $taxes = (new PaymentTaxProrater)->prorate($invoice, '476.665')['taxes'];

    expect($taxes)->toHaveCount(4)
        ->and(array_column($taxes, 'base'))->toBe(['500.000000', '500.000000', '500.000000', '25.000000'])
        ->and(array_column($taxes, 'amount'))->toBe(['80.000000', '50.000000', '53.333500', null])
        ->and(array_column($taxes, 'amount_rounded'))->toBe(['80.00', '50.00', '53.33', '0.00'])
        ->and(array_column($taxes, 'kind'))->toBe(['traslado', 'retencion', 'retencion', 'traslado'])
        ->and(array_column($taxes, 'position'))->toBe([0, 1, 2, 3]);
});

test('proporción no exacta: BaseDR e ImporteDR con 6 decimales', function () {
    $company = Company::factory()->create();
    $invoice = p635TaxInvoice($company, '1160', [['1000', '160', [p635Iva('1000', '160')]]]);

    $tax = (new PaymentTaxProrater)->prorate($invoice, '386.67')['taxes'][0];

    // 386.67 / 1160 = 0.333336206896…; base = 333.336207; importe = base × 0.16.
    expect($tax['base'])->toBe('333.336207')
        ->and($tax['amount'])->toBe('53.333793')
        ->and($tax['amount_rounded'])->toBe('53.33');
});

test('factura sin impuestos: ObjetoImpDR 01 y sin ImpuestosDR', function () {
    $company = Company::factory()->create();
    $invoice = p635TaxInvoice($company, '100', [['100', '0', []]]);

    expect((new PaymentTaxProrater)->prorate($invoice, '50'))->toBe(['taxability' => '01', 'taxes' => []]);
});

test('una línea con impuestos sin desglose detiene el prorrateo', function () {
    $company = Company::factory()->create();
    $invoice = p635TaxInvoice($company, '116', [['100', '16', []]]);

    expect(fn () => (new PaymentTaxProrater)->prorate($invoice, '50'))->toThrow(RuntimeException::class);
});

test('guarda los impuestos de la asignación, reemplazando los anteriores', function () {
    $company = Company::factory()->create();
    $invoice = p635TaxInvoice($company, '1160', [['1000', '160', [p635Iva('1000', '160')]]]);
    $allocation = PaymentAllocation::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id, 'amount' => 580]);
    $prorater = new PaymentTaxProrater;

    $prorater->store($allocation, $prorater->prorate($invoice, '580')['taxes']);
    $prorater->store($allocation, $prorater->prorate($invoice, '290')['taxes']);

    $rows = PaymentAllocationTax::withoutGlobalScope(CompanyScope::class)->where('payment_allocation_id', $allocation->id)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->base)->toBe('250.000000')
        ->and($rows->first()->amount)->toBe('40.000000')
        ->and($rows->first()->company_id)->toBe($company->id);
});
