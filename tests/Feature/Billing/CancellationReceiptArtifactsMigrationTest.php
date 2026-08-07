<?php

use App\Models\Company;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

test('migracion del acuse es valida y agrega solo metadata a invoices', function () {
    expect(Artisan::call('migrate', ['--pretend' => true]))->toBe(0);

    expect(Schema::hasColumns('invoices', [
        'cancellation_receipt_xml_path',
        'cancellation_receipt_pdf_path',
        'cancellation_receipt_xml_sha256',
        'cancellation_receipt_pdf_sha256',
        'cancellation_receipt_xml_size',
        'cancellation_receipt_pdf_size',
        'cancellation_receipt_downloaded_at',
        'cancellation_receipt_status',
        'cancellation_receipt_last_error',
    ]))->toBeTrue();
});

test('metadata del acuse no es mass assignable', function () {
    $invoice = Invoice::factory()->create(['company_id' => Company::factory()->create()->id]);
    $invoice->fill([
        'cancellation_receipt_xml_path' => 'public/evil.xml',
        'cancellation_receipt_status' => 'stored',
        'cancellation_receipt_xml_sha256' => str_repeat('a', 64),
    ]);

    expect($invoice->cancellation_receipt_xml_path)->toBeNull()
        ->and($invoice->cancellation_receipt_status)->toBeNull()
        ->and($invoice->cancellation_receipt_xml_sha256)->toBeNull();
});

test('rutas y error del acuse estan hidden y timestamp/tamanos tienen casts seguros', function () {
    $invoice = Invoice::factory()->create(['company_id' => Company::factory()->create()->id]);
    $invoice->forceFill([
        'cancellation_receipt_xml_path' => 'cancellation-receipts/private.xml',
        'cancellation_receipt_pdf_path' => 'cancellation-receipts/private.pdf',
        'cancellation_receipt_last_error' => 'detalle interno',
        'cancellation_receipt_xml_size' => 123,
        'cancellation_receipt_pdf_size' => 456,
        'cancellation_receipt_downloaded_at' => now(),
    ])->save();

    $fresh = $invoice->fresh();
    expect($fresh->toArray())->not->toHaveKeys([
        'cancellation_receipt_xml_path',
        'cancellation_receipt_pdf_path',
        'cancellation_receipt_last_error',
    ])
        ->and($fresh->cancellation_receipt_xml_size)->toBeInt()->toBe(123)
        ->and($fresh->cancellation_receipt_pdf_size)->toBeInt()->toBe(456)
        ->and($fresh->cancellation_receipt_downloaded_at)->toBeInstanceOf(CarbonImmutable::class);
});
