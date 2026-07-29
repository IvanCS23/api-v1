<?php

use App\Contracts\Billing\PacProvider;
use App\Exceptions\Billing\PacUnavailableException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Cubre específicamente el comportamiento de reintento de
 * FacturapiProvider (ya implementado en Fase 6.1, `client()->retry(2,
 * 200, ...)`), no ejercitado todavía por FacturapiProviderResponseTest.
 * No modifica el adaptador — solo agrega la cobertura faltante.
 */
beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_RETRY',
    ]);
});

function fakeInvoiceForRetryTest(): Invoice
{
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);

    app(CurrentTenant::class)->set($company->id);

    return $invoice->fresh(['items']);
}

test('createInvoice reintenta ante un fallo transitorio de conexión y tiene éxito en el segundo intento', function () {
    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        if ($attempts === 1) {
            throw new ConnectionException('Connection timed out después de 5 segundos');
        }

        return Http::response(['id' => 'inv_retry_ok', 'status' => 'valid'], 200);
    });

    $result = app(PacProvider::class)->createInvoice(fakeInvoiceForRetryTest());

    expect($attempts)->toBe(2)
        ->and($result->externalId)->toBe('inv_retry_ok');
});

test('createInvoice agota los reintentos y propaga ConnectionException si todos los intentos fallan por conexión', function () {
    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('Connection timed out después de 5 segundos');
    });

    expect(fn () => app(PacProvider::class)->createInvoice(fakeInvoiceForRetryTest()))
        ->toThrow(ConnectionException::class);

    expect($attempts)->toBe(2);
});

test('createInvoice NO reintenta ante una respuesta HTTP de error ya recibida (5xx) — solo fallos de conexión son reintentables', function () {
    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        return Http::response(['message' => 'Error interno del PAC'], 500);
    });

    expect(fn () => app(PacProvider::class)->createInvoice(fakeInvoiceForRetryTest()))
        ->toThrow(PacUnavailableException::class);

    expect($attempts)->toBe(1);
});
