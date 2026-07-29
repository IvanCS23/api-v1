<?php

use App\Contracts\Billing\PacProvider;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_PAYLOAD',
    ]);
});

test('el payload de creación se construye únicamente desde el snapshot de Invoice/InvoiceItem', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'folio' => 'FAC-00000001',
        'currency' => 'MXN',
        'client_name' => 'Cliente Snapshot SA',
        'client_rfc' => 'CSN010101AAA',
        'client_regimen_fiscal' => '601',
        'client_uso_cfdi' => 'G03',
        'client_codigo_postal' => '54930',
        'subtotal' => 100,
        'discount_total' => 0,
        'tax_total' => 16,
        'total' => 116,
    ]);
    InvoiceItem::factory()->create([
        'company_id' => $company->id,
        'invoice_id' => $invoice->id,
        'description' => 'Descripción comercial de la línea',
        'product_clave_producto' => 'ABC12345',
        'product_clave_unidad' => 'H87',
        'product_no_identificacion' => 'NOID-1',
        'product_objeto_imp' => '02',
        'quantity' => 2,
        'unit_price' => 50,
        'discount' => 0,
        'tax_code' => '002',
        'tax_rate_value' => 0.16,
        'tax_type' => 'traslado',
        'tax_factor_type' => 'tasa',
    ]);

    Http::fake(['*' => Http::response(['id' => 'inv_123', 'status' => 'pending'], 200)]);

    app(CurrentTenant::class)->set($company->id);
    app(PacProvider::class)->createInvoice($invoice->fresh(['items']));

    Http::assertSent(function ($request) {
        $body = $request->data();

        expect($body['folio'])->toBe('FAC-00000001')
            ->and($body['currency'])->toBe('MXN')
            ->and($body['customer']['legal_name'])->toBe('Cliente Snapshot SA')
            ->and($body['customer']['tax_id'])->toBe('CSN010101AAA')
            ->and($body['customer']['tax_system'])->toBe('601')
            ->and($body['customer']['use'])->toBe('G03')
            ->and($body['customer']['address']['zip'])->toBe('54930')
            ->and($body['items'])->toHaveCount(1)
            ->and($body['items'][0]['description'])->toBe('Descripción comercial de la línea')
            ->and($body['items'][0]['product_key'])->toBe('ABC12345')
            ->and($body['items'][0]['unit_key'])->toBe('H87')
            ->and($body['items'][0]['no_identification'])->toBe('NOID-1')
            ->and($body['items'][0]['tax_object'])->toBe('02')
            ->and($body['items'][0]['quantity'])->toBe(2.0)
            ->and($body['items'][0]['unit_price'])->toBe(50.0)
            ->and($body['items'][0]['taxes'][0]['code'])->toBe('002')
            ->and($body['items'][0]['taxes'][0]['rate'])->toBe(0.16)
            ->and($body['totals'])->toBe(['subtotal' => 100.0, 'discount' => 0.0, 'tax' => 16.0, 'total' => 116.0]);

        return true;
    });
});

test('el payload no consulta valores modificados de Client ni Product después de facturar', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'client_name' => 'Nombre Al Momento De Facturar',
        'client_rfc' => 'ORIG010101AAA',
    ]);
    $item = InvoiceItem::factory()->create([
        'company_id' => $company->id,
        'invoice_id' => $invoice->id,
        'product_clave_producto' => 'ORIGINAL1',
    ]);

    // Modifica el Client y el Product REALES (si existen) después de
    // que la Invoice ya tiene su snapshot congelado.
    if ($invoice->client_id !== null) {
        \App\Models\Client::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)
            ->where('id', $invoice->client_id)
            ->update(['name' => 'NOMBRE CAMBIADO DESPUES', 'rfc' => 'CAMBIADO99']);
    }
    if ($item->product_id !== null) {
        \App\Models\Product::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)
            ->where('id', $item->product_id)
            ->update(['clave_producto' => 'CAMBIADO99']);
    }

    Http::fake(['*' => Http::response(['id' => 'inv_456', 'status' => 'pending'], 200)]);

    app(CurrentTenant::class)->set($company->id);
    app(PacProvider::class)->createInvoice($invoice->fresh(['items']));

    Http::assertSent(function ($request) {
        $body = $request->data();

        // El payload sigue reflejando el snapshot original, no los
        // valores en vivo recién modificados de Client/Product.
        expect($body['customer']['legal_name'])->toBe('Nombre Al Momento De Facturar')
            ->and($body['customer']['tax_id'])->toBe('ORIG010101AAA')
            ->and($body['items'][0]['product_key'])->toBe('ORIGINAL1');

        return true;
    });
});

test('el payload de una empresa nunca mezcla datos de otra empresa (aislamiento multi-tenant en el adaptador)', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $invoiceA = Invoice::factory()->create(['company_id' => $companyA->id, 'client_name' => 'Empresa A Cliente', 'folio' => 'FAC-A-1']);
    InvoiceItem::factory()->create(['company_id' => $companyA->id, 'invoice_id' => $invoiceA->id, 'description' => 'Linea de A']);

    $invoiceB = Invoice::factory()->create(['company_id' => $companyB->id, 'client_name' => 'Empresa B Cliente', 'folio' => 'FAC-B-1']);
    InvoiceItem::factory()->create(['company_id' => $companyB->id, 'invoice_id' => $invoiceB->id, 'description' => 'Linea de B']);

    Http::fake(['*' => Http::response(['id' => 'inv_a', 'status' => 'pending'], 200)]);
    app(CurrentTenant::class)->set($companyA->id);
    app(PacProvider::class)->createInvoice($invoiceA->fresh(['items']));

    Http::assertSent(function ($request) {
        $body = $request->data();
        expect($body['folio'])->toBe('FAC-A-1')
            ->and($body['customer']['legal_name'])->toBe('Empresa A Cliente')
            ->and($body['items'][0]['description'])->toBe('Linea de A')
            ->and($body['customer']['legal_name'])->not->toBe('Empresa B Cliente');

        return true;
    });

    Http::fake(['*' => Http::response(['id' => 'inv_b', 'status' => 'pending'], 200)]);
    app(CurrentTenant::class)->set($companyB->id);
    app(PacProvider::class)->createInvoice($invoiceB->fresh(['items']));

    Http::assertSent(function ($request) {
        $body = $request->data();
        expect($body['folio'])->toBe('FAC-B-1')
            ->and($body['customer']['legal_name'])->toBe('Empresa B Cliente')
            ->and($body['items'][0]['description'])->toBe('Linea de B');

        return true;
    });
});
