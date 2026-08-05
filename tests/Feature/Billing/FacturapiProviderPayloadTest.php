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

// ==================== CORRECCIÓN PUNTUAL: campos opcionales nunca como null ====================
//
// Reproducido por primera vez con una llamada real a Facturapi TEST:
// `items[].product.sku` se enviaba como `null` cuando el snapshot no lo
// traía, y Facturapi lo rechazó ("tipo inválido") porque `sku` es
// OPCIONAL (la clave puede omitirse) pero de tipo `string` (nunca acepta
// `null`). Mismo criterio aplicado a los sub-campos opcionales de
// `customer.address` (ver `nullableStringOrOmit()` en FacturapiProvider).

test('sku ausente en el payload cuando el snapshot no lo trae, incluso si el Product vivo tiene un SKU distinto — nunca consulta el Product vivo', function () {
    $company = Company::factory()->create();
    $product = \App\Models\Product::factory()->create([
        'company_id' => $company->id,
        'no_identificacion' => 'SKU-ORIGINAL',
    ]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    InvoiceItem::factory()->create([
        'company_id' => $company->id,
        'invoice_id' => $invoice->id,
        'product_id' => $product->id,
        'product_no_identificacion' => null,
    ]);

    // El Product se modifica DESPUÉS de que la Invoice ya congeló su
    // snapshot (product_no_identificacion sigue null) — mismo escenario
    // reportado con Product #14 / Invoice #1.
    $product->update(['no_identificacion' => 'SKU-NUEVO']);

    Http::fake(['*' => Http::response(['id' => 'inv_sku_null', 'status' => 'pending'], 200)]);
    app(CurrentTenant::class)->set($company->id);

    \Illuminate\Support\Facades\DB::enableQueryLog();
    app(PacProvider::class)->createInvoice(requestForPayloadTest($invoice->fresh(['items'])));
    $queries = \Illuminate\Support\Facades\DB::getQueryLog();
    \Illuminate\Support\Facades\DB::disableQueryLog();

    // buildBasePayload() nunca consulta la tabla `products` — el payload
    // se construye exclusivamente desde el snapshot de InvoiceItem.
    expect(collect($queries)->contains(fn ($q) => str_contains(strtolower($q['query']), 'from `products`') || str_contains(strtolower($q['query']), 'from "products"')))
        ->toBeFalse();

    Http::assertSent(function ($request) {
        $body = $request->data();

        expect($body['items'][0]['product'])->not->toHaveKey('sku');

        $raw = json_encode($body);
        expect($raw)->not->toContain('SKU-NUEVO')
            ->and($raw)->not->toContain('"sku":null')
            ->and($raw)->not->toContain('"sku":"null"');

        return true;
    });
});

test('sku presente y exacto cuando el snapshot lo trae', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    InvoiceItem::factory()->create([
        'company_id' => $company->id,
        'invoice_id' => $invoice->id,
        'product_no_identificacion' => 'SKU-SNAPSHOT',
    ]);

    Http::fake(['*' => Http::response(['id' => 'inv_sku_present', 'status' => 'pending'], 200)]);
    app(CurrentTenant::class)->set($company->id);

    app(PacProvider::class)->createInvoice(requestForPayloadTest($invoice->fresh(['items'])));

    Http::assertSent(function ($request) {
        $body = $request->data();

        expect($body['items'][0]['product']['sku'])->toBe('SKU-SNAPSHOT');

        return true;
    });
});

test('customer.address omite las claves opcionales sin valor (nunca las envía como null); zip siempre se envía', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'client_codigo_postal' => '54930',
        'client_calle' => null,
        'client_no_exterior' => null,
        'client_no_interior' => null,
        'client_colonia' => null,
        'client_localidad' => null,
        'client_municipio' => null,
        'client_estado' => null,
        'client_pais' => null,
    ]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);

    Http::fake(['*' => Http::response(['id' => 'inv_addr_null', 'status' => 'pending'], 200)]);
    app(CurrentTenant::class)->set($company->id);

    app(PacProvider::class)->createInvoice(requestForPayloadTest($invoice->fresh(['items'])));

    Http::assertSent(function ($request) {
        $body = $request->data();
        $address = $body['customer']['address'];

        expect($address)->toBe(['zip' => '54930']);

        $raw = json_encode($body);
        expect($raw)->not->toContain(':null');

        return true;
    });
});

// ==================== CORRECCIÓN PUNTUAL: tax_included / semántica fiscal ====================
//
// Reproducido con la primera creación real de un draft en Facturapi
// TEST: al omitir `product.tax_included`, Facturapi asumió su propio
// default (`true`, precio CON IVA incluido) y el draft devuelto quedó
// inconsistente con el snapshot local (subtotal=100/tax=16/total=116 —
// `unit_price=100` es SIEMPRE precio antes de impuestos en este ERP, ver
// SaleCalculator/InvoiceCalculator, que esta corrección NO toca).

test('tax_included se envía explícitamente como false — unit_price es precio antes de impuestos, nunca se recalcula ni se deja el default de Facturapi', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'subtotal' => 100,
        'discount_total' => 0,
        'tax_total' => 16,
        'total' => 116,
    ]);
    InvoiceItem::factory()->create([
        'company_id' => $company->id,
        'invoice_id' => $invoice->id,
        'quantity' => 1,
        'unit_price' => 100,
        'discount' => 0,
        'tax_code' => '002',
        'tax_rate_value' => 0.16,
        'tax_type' => 'traslado',
        'tax_factor_type' => 'tasa',
    ]);

    Http::fake(['*' => Http::response(['id' => 'inv_tax_included', 'status' => 'pending'], 200)]);
    app(CurrentTenant::class)->set($company->id);

    app(PacProvider::class)->createInvoice(requestForPayloadTest($invoice->fresh(['items'])));

    Http::assertSent(function ($request) {
        $product = $request->data()['items'][0]['product'];

        expect($product['tax_included'])->toBeFalse()
            ->and($product['price'])->toBe(100.0)
            ->and($product['taxes'][0]['type'])->toBe('IVA')
            ->and($product['taxes'][0]['rate'])->toBe(0.16);

        return true;
    });

    // El mapper preserva exactamente la interpretación fiscal ya
    // calculada localmente (InvoiceCalculator) — nunca la recalcula:
    // subtotal=100, tax=16, total=116 con price=100 + tax_included=false
    // + rate=0.16 es la única combinación consistente con esos importes.
    $fresh = $invoice->fresh();
    expect((float) $fresh->subtotal)->toBe(100.0)
        ->and((float) $fresh->tax_total)->toBe(16.0)
        ->and((float) $fresh->total)->toBe(116.0);
});

test('el objeto taxes[] nunca fabrica una clave "withholding": Facturapi no documenta esa distinción (ver InvoicePacReadinessService, Fase 6.2.3) — retención ya se bloquea antes de llegar aquí', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    $item = InvoiceItem::factory()->create([
        'company_id' => $company->id,
        'invoice_id' => $invoice->id,
        'tax_code' => '002',
        'tax_rate_value' => 0.16,
        'tax_type' => 'traslado',
        'tax_factor_type' => 'tasa',
    ]);

    // Garantía de dominio (no del payload): la línea que llega aquí
    // nunca es una retención — InvoicePacReadinessService ya bloquea
    // tax_type=retencion antes de que IssueInvoiceService/
    // CreatePacDraftInvoiceService lleguen a llamar a este Provider.
    expect($item->tax_type)->toBe(\App\Enums\TaxType::Traslado->value);

    Http::fake(['*' => Http::response(['id' => 'inv_no_withholding', 'status' => 'pending'], 200)]);
    app(CurrentTenant::class)->set($company->id);

    app(PacProvider::class)->createInvoice(requestForPayloadTest($invoice->fresh(['items'])));

    Http::assertSent(function ($request) {
        $taxes = $request->data()['items'][0]['product']['taxes'][0];

        expect($taxes)->not->toHaveKey('withholding')
            ->and($taxes)->toBe(['type' => 'IVA', 'rate' => 0.16]);

        return true;
    });
});

test('IVA 0% (tasa cero, ya soportado): tax_included sigue false, rate=0.0, sin regresión', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    InvoiceItem::factory()->create([
        'company_id' => $company->id,
        'invoice_id' => $invoice->id,
        'unit_price' => 50,
        'tax_code' => '002',
        'tax_rate_value' => 0,
        'tax_type' => 'traslado',
        'tax_factor_type' => 'tasa',
    ]);

    Http::fake(['*' => Http::response(['id' => 'inv_iva_cero', 'status' => 'pending'], 200)]);
    app(CurrentTenant::class)->set($company->id);

    app(PacProvider::class)->createInvoice(requestForPayloadTest($invoice->fresh(['items'])));

    Http::assertSent(function ($request) {
        $product = $request->data()['items'][0]['product'];

        expect($product['tax_included'])->toBeFalse()
            ->and($product['taxes'][0]['type'])->toBe('IVA')
            ->and($product['taxes'][0]['rate'])->toBe(0.0);

        return true;
    });
});

test('exento (sin tax_code): tax_included sigue false, taxes=[] vacío, sin regresión', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    InvoiceItem::factory()->create([
        'company_id' => $company->id,
        'invoice_id' => $invoice->id,
        'unit_price' => 50,
        'tax_code' => null,
        'tax_rate_value' => null,
        'tax_type' => null,
        'tax_factor_type' => null,
    ]);

    Http::fake(['*' => Http::response(['id' => 'inv_exento', 'status' => 'pending'], 200)]);
    app(CurrentTenant::class)->set($company->id);

    app(PacProvider::class)->createInvoice(requestForPayloadTest($invoice->fresh(['items'])));

    Http::assertSent(function ($request) {
        $product = $request->data()['items'][0]['product'];

        expect($product['tax_included'])->toBeFalse()
            ->and($product['taxes'])->toBe([]);

        return true;
    });
});

test('no cambia con modificaciones del TaxRate vivo: el payload usa exclusivamente el snapshot de InvoiceItem', function () {
    $company = Company::factory()->create();
    $taxRate = \App\Models\TaxRate::factory()->create([
        'rate' => 0.16,
        'tax_type' => \App\Enums\TaxType::Traslado,
    ]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    InvoiceItem::factory()->create([
        'company_id' => $company->id,
        'invoice_id' => $invoice->id,
        'tax_rate_id' => $taxRate->id,
        'tax_code' => '002',
        'tax_rate_value' => 0.16,
        'tax_type' => 'traslado',
        'tax_factor_type' => 'tasa',
    ]);

    // Modifica el TaxRate REAL después de que la Invoice ya congeló su
    // snapshot.
    $taxRate->update(['rate' => 0.99, 'tax_type' => \App\Enums\TaxType::Retencion]);

    Http::fake(['*' => Http::response(['id' => 'inv_taxrate_live', 'status' => 'pending'], 200)]);
    app(CurrentTenant::class)->set($company->id);

    \Illuminate\Support\Facades\DB::enableQueryLog();
    app(PacProvider::class)->createInvoice(requestForPayloadTest($invoice->fresh(['items'])));
    $queries = \Illuminate\Support\Facades\DB::getQueryLog();
    \Illuminate\Support\Facades\DB::disableQueryLog();

    expect(collect($queries)->contains(fn ($q) => str_contains(strtolower($q['query']), 'from `tax_rates`') || str_contains(strtolower($q['query']), 'from "tax_rates"')))
        ->toBeFalse();

    Http::assertSent(function ($request) {
        $taxes = $request->data()['items'][0]['product']['taxes'][0];

        // Sigue reflejando el snapshot original (0.16), nunca el 0.99
        // recién modificado en el TaxRate vivo.
        expect($taxes['rate'])->toBe(0.16);

        return true;
    });
});

test('customer.address incluye las claves opcionales cuando el snapshot sí las trae', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'client_codigo_postal' => '54930',
        'client_calle' => 'Av. Siempre Viva',
        'client_no_exterior' => '123',
        'client_no_interior' => 'B',
        'client_colonia' => 'Centro',
        'client_localidad' => 'Naucalpan',
        'client_municipio' => 'Naucalpan',
        'client_estado' => 'MEX',
        'client_pais' => 'MEX',
    ]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);

    Http::fake(['*' => Http::response(['id' => 'inv_addr_full', 'status' => 'pending'], 200)]);
    app(CurrentTenant::class)->set($company->id);

    app(PacProvider::class)->createInvoice(requestForPayloadTest($invoice->fresh(['items'])));

    Http::assertSent(function ($request) {
        $body = $request->data();
        $address = $body['customer']['address'];

        expect($address)->toBe([
            'street' => 'Av. Siempre Viva',
            'exterior' => '123',
            'interior' => 'B',
            'neighborhood' => 'Centro',
            'city' => 'Naucalpan',
            'municipality' => 'Naucalpan',
            'zip' => '54930',
            'state' => 'MEX',
            'country' => 'MEX',
        ]);

        return true;
    });
});
