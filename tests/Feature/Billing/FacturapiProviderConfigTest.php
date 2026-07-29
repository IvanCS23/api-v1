<?php

use App\Contracts\Billing\PacProvider;
use App\Exceptions\Billing\PacAuthenticationException;
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

test('la petición usa la URL base configurada', function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_URL',
    ]);
    Http::fake(['*' => Http::response(['id' => 'x', 'status' => 'pending'], 200)]);

    app(PacProvider::class)->createInvoice(fakeInvoiceForConfigTest());

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://example-pac.test/v2/invoices'));
});

test('la petición envía Authorization Bearer con la llave configurada', function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_SECRET_ABC',
    ]);
    Http::fake(['*' => Http::response(['id' => 'x', 'status' => 'pending'], 200)]);

    app(PacProvider::class)->createInvoice(fakeInvoiceForConfigTest());

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk_test_SECRET_ABC'));
});

test('el adaptador se construye con el timeout y connect_timeout configurados', function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_TIMEOUT',
        'services.facturapi.timeout' => 30,
        'services.facturapi.connect_timeout' => 3,
    ]);

    $provider = app(PacProvider::class);
    $reflection = new ReflectionClass($provider);

    expect($reflection->getProperty('timeout')->getValue($provider))->toBe(30)
        ->and($reflection->getProperty('connectTimeout')->getValue($provider))->toBe(3);
});

test('sin API key configurada, la petición igual se envía con un Bearer vacío, sin romper ni enviar la palabra null', function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => null,
    ]);
    Http::fake(['*' => Http::response(['id' => 'x', 'status' => 'pending'], 200)]);

    app(PacProvider::class)->createInvoice(fakeInvoiceForConfigTest());

    Http::assertSent(function ($request) {
        $auth = trim($request->header('Authorization')[0] ?? '');

        expect($auth)->toBe('Bearer')
            ->and($auth)->not->toContain('null');

        return true;
    });
});

test('la API key nunca aparece en el mensaje ni en el código de una excepción PAC', function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_MUY_SECRETA_12345',
    ]);
    Http::fake(['*' => Http::response(['message' => 'No autorizado', 'code' => 'unauthorized'], 401)]);

    try {
        app(PacProvider::class)->createInvoice(fakeInvoiceForConfigTest());
        test()->fail('Se esperaba PacAuthenticationException');
    } catch (PacAuthenticationException $e) {
        expect($e->getMessage())->not->toContain('sk_test_MUY_SECRETA_12345')
            ->and((string) $e->pacCode)->not->toContain('sk_test_MUY_SECRETA_12345')
            ->and((string) $e)->not->toContain('sk_test_MUY_SECRETA_12345');
    }
});
