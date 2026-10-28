<?php

use App\Data\Billing\PacInvoiceRequest;
use App\Exceptions\Billing\PacAuthenticationException;
use App\Exceptions\Billing\PacConfigurationException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Support\Facades\Http;

function fakeInvoiceForConfigTest(): Invoice
{
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);

    app(CurrentTenant::class)->set($company->id);

    return $invoice->fresh(['items']);
}

function requestForConfigTest(Invoice $invoice): PacInvoiceRequest
{
    return new PacInvoiceRequest(
        invoice: $invoice,
        idempotencyKey: "erp-invoice:{$invoice->company_id}:{$invoice->id}:v1",
        externalId: "company-{$invoice->company_id}-invoice-{$invoice->id}",
    );
}

test('la petición usa la URL base configurada', function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_URL',
    ]);
    Http::fake(['*' => Http::response(['id' => 'x', 'status' => 'pending'], 200)]);

    $invoice = fakeInvoiceForConfigTest();
    facturapiProviderFromConfig()->createInvoice(requestForConfigTest($invoice));

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://example-pac.test/v2/invoices'));
});

test('la petición envía Authorization Bearer con la llave configurada', function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_SECRET_ABC',
    ]);
    Http::fake(['*' => Http::response(['id' => 'x', 'status' => 'pending'], 200)]);

    $invoice = fakeInvoiceForConfigTest();
    facturapiProviderFromConfig()->createInvoice(requestForConfigTest($invoice));

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk_test_SECRET_ABC'));
});

test('el adaptador se construye con el timeout y connect_timeout configurados', function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_TIMEOUT',
        'services.facturapi.timeout' => 30,
        'services.facturapi.connect_timeout' => 3,
    ]);

    $provider = facturapiProviderFromConfig();
    $reflection = new ReflectionClass($provider);

    expect($reflection->getProperty('timeout')->getValue($provider))->toBe(30)
        ->and($reflection->getProperty('connectTimeout')->getValue($provider))->toBe(3);
});

test('sin API key configurada no se envía ninguna petición (Fase 6.19: antes salía con Bearer vacío)', function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => null,
    ]);
    Http::fake(['*' => Http::response(['id' => 'x', 'status' => 'pending'], 200)]);

    $invoice = fakeInvoiceForConfigTest();

    expect(fn () => facturapiProviderFromConfig()->createInvoice(requestForConfigTest($invoice)))
        ->toThrow(PacConfigurationException::class);

    Http::assertNothingSent();
});

test('una stamp_timezone inválida o vacía bloquea toda petición antes de la red (Fase 6.20)', function (?string $timezone) {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_TZ',
        'billing.facturapi.stamp_timezone' => $timezone,
    ]);
    Http::fake(['*' => Http::response(['id' => 'x', 'status' => 'valid', 'livemode' => false], 200)]);

    $invoice = fakeInvoiceForConfigTest();

    try {
        facturapiProviderFromConfig()->createInvoice(requestForConfigTest($invoice));
        $this->fail('Se esperaba PacConfigurationException.');
    } catch (PacConfigurationException $e) {
        expect($e->pacCode)->toBe('stamp_timezone_invalid');
    }

    expect(fn () => facturapiProviderFromConfig()->stampDraftInvoice('inv_tz_invalid'))
        ->toThrow(PacConfigurationException::class);

    Http::assertNothingSent();
})->with([
    'identificador inexistente' => ['Mexico/Centro'],
    'vacía' => [''],
    'null' => [null],
]);

test('la API key nunca aparece en el mensaje ni en el código de una excepción PAC', function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_MUY_SECRETA_12345',
    ]);
    Http::fake(['*' => Http::response(['message' => 'No autorizado', 'code' => 'unauthorized'], 401)]);

    $invoice = fakeInvoiceForConfigTest();

    try {
        facturapiProviderFromConfig()->createInvoice(requestForConfigTest($invoice));
        test()->fail('Se esperaba PacAuthenticationException');
    } catch (PacAuthenticationException $e) {
        expect($e->getMessage())->not->toContain('sk_test_MUY_SECRETA_12345')
            ->and((string) $e->pacCode)->not->toContain('sk_test_MUY_SECRETA_12345')
            ->and((string) $e)->not->toContain('sk_test_MUY_SECRETA_12345');
    }
});

test('name() identifica el proveedor de forma estable, sin Reflection sobre la clase concreta', function () {
    expect(facturapiProviderFromConfig()->name())->toBe('facturapi');
});
