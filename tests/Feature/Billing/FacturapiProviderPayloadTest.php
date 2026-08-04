<?php

use App\Contracts\Billing\PacProvider;
use App\Data\Billing\PacInvoiceRequest;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Support\Facades\Http;

/**
 * Estructura auditada contra docs.facturapi.io/api/ en Fase 6.2.1 — ver
 * el reporte de entrega para el detalle completo de diferencias frente
 * a la forma original de Fase 6.1 (customer.use -> use a nivel raíz,
 * customer.address.locality -> customer.address.city, items[] plano ->
 * items[].product.{...}, sin `totals`, sin `folio`/`folio_number`, con
 * `external_id`/`idempotency_key`). `payment_form` no se envía ni se
 * valida localmente (columna inexistente en Invoice — ver auditoría);
 * eso no afecta estas pruebas de estructura.
 */
beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_PAYLOAD',
    ]);
});

function requestForPayloadTest(Invoice $invoice, string $externalId = 'ext-test'): PacInvoiceRequest
{
    return new PacInvoiceRequest(
        invoice: $invoice,
        idempotencyKey: "erp-invoice:{$invoice->company_id}:{$invoice->id}:v1",
        externalId: $externalId,
    );
}

test('el payload de creación se construye únicamente desde el snapshot de Invoice/InvoiceItem, con la forma oficial de Facturapi', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'currency' => 'MXN',
        'client_name' => 'Cliente Snapshot SA',
        'client_rfc' => 'CSN010101AAA',
        'client_regimen_fiscal' => '601',
        'client_uso_cfdi' => 'G03',
        'client_codigo_postal' => '54930',
        'client_localidad' => 'Naucalpan',
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
    app(PacProvider::class)->createInvoice(requestForPayloadTest($invoice->fresh(['items']), 'company-'.$company->id.'-invoice-'.$invoice->id));

    Http::assertSent(function ($request) use ($company, $invoice) {
        $body = $request->data();

        expect($body)->not->toHaveKey('totals')
            ->and($body)->not->toHaveKey('folio')
            ->and($body)->not->toHaveKey('folio_number')
            ->and($body['currency'])->toBe('MXN')
            ->and($body['use'])->toBe('G03')
            ->and($body['external_id'])->toBe("company-{$company->id}-invoice-{$invoice->id}")
            ->and($body['idempotency_key'])->toBe("erp-invoice:{$company->id}:{$invoice->id}:v1")
            ->and($body['customer']['legal_name'])->toBe('Cliente Snapshot SA')
            ->and($body['customer']['tax_id'])->toBe('CSN010101AAA')
            ->and($body['customer']['tax_system'])->toBe('601')
            ->and($body['customer'])->not->toHaveKey('use')
            ->and($body['customer']['address']['zip'])->toBe('54930')
            ->and($body['customer']['address']['city'])->toBe('Naucalpan')
            ->and($body['customer']['address'])->not->toHaveKey('locality')
            ->and($body['items'])->toHaveCount(1)
            ->and($body['items'][0]['quantity'])->toBe(2.0)
            ->and($body['items'][0]['discount'])->toBe(0.0)
            ->and($body['items'][0]['product']['description'])->toBe('Descripción comercial de la línea')
            ->and($body['items'][0]['product']['product_key'])->toBe('ABC12345')
            ->and($body['items'][0]['product']['unit_key'])->toBe('H87')
            ->and($body['items'][0]['product']['price'])->toBe(50.0)
            ->and($body['items'][0]['product']['sku'])->toBe('NOID-1')
            ->and($body['items'][0]['product']['taxability'])->toBe('02')
            ->and($body['items'][0]['product']['taxes'][0]['type'])->toBe('IVA')
            ->and($body['items'][0]['product']['taxes'][0]['rate'])->toBe(0.16)
            ->and($body['items'][0])->not->toHaveKey('unit_price')
            ->and($body['items'][0])->not->toHaveKey('description');

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
    app(PacProvider::class)->createInvoice(requestForPayloadTest($invoice->fresh(['items'])));

    Http::assertSent(function ($request) {
        $body = $request->data();

        // El payload sigue reflejando el snapshot original, no los
        // valores en vivo recién modificados de Client/Product.
        expect($body['customer']['legal_name'])->toBe('Nombre Al Momento De Facturar')
            ->and($body['customer']['tax_id'])->toBe('ORIG010101AAA')
            ->and($body['items'][0]['product']['product_key'])->toBe('ORIGINAL1');

        return true;
    });
});

test('el payload de una empresa nunca mezcla datos de otra empresa (aislamiento multi-tenant en el adaptador)', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $invoiceA = Invoice::factory()->create(['company_id' => $companyA->id, 'client_name' => 'Empresa A Cliente']);
    InvoiceItem::factory()->create(['company_id' => $companyA->id, 'invoice_id' => $invoiceA->id, 'description' => 'Linea de A']);

    $invoiceB = Invoice::factory()->create(['company_id' => $companyB->id, 'client_name' => 'Empresa B Cliente']);
    InvoiceItem::factory()->create(['company_id' => $companyB->id, 'invoice_id' => $invoiceB->id, 'description' => 'Linea de B']);

    Http::fake(['*' => Http::response(['id' => 'inv_a', 'status' => 'pending'], 200)]);
    app(CurrentTenant::class)->set($companyA->id);
    app(PacProvider::class)->createInvoice(requestForPayloadTest($invoiceA->fresh(['items'])));

    Http::assertSent(function ($request) {
        $body = $request->data();
        expect($body['customer']['legal_name'])->toBe('Empresa A Cliente')
            ->and($body['items'][0]['product']['description'])->toBe('Linea de A')
            ->and($body['customer']['legal_name'])->not->toBe('Empresa B Cliente');

        return true;
    });

    Http::fake(['*' => Http::response(['id' => 'inv_b', 'status' => 'pending'], 200)]);
    app(CurrentTenant::class)->set($companyB->id);
    app(PacProvider::class)->createInvoice(requestForPayloadTest($invoiceB->fresh(['items'])));

    Http::assertSent(function ($request) {
        $body = $request->data();
        expect($body['customer']['legal_name'])->toBe('Empresa B Cliente')
            ->and($body['items'][0]['product']['description'])->toBe('Linea de B');

        return true;
    });
});

// Nota (Fase 6.2.3): las pruebas de "snapshot fiscal incompleto bloquea
// la emisión" que vivían aquí se movieron a
// tests/Feature/Billing/InvoicePacReadinessServiceTest.php —
// FacturapiProvider ya NO valida completitud del snapshot (se
// concentra únicamente en traducir el payload); ese concepto se
// centralizó en InvoicePacReadinessService, invocado por
// IssueInvoiceService ANTES de llamar a este Provider.
