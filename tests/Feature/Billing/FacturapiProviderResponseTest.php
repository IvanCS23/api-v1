<?php

use App\Contracts\Billing\PacProvider;
use App\Data\Billing\PacInvoiceResult;
use App\Exceptions\Billing\PacAuthenticationException;
use App\Exceptions\Billing\PacRateLimitException;
use App\Exceptions\Billing\PacUnavailableException;
use App\Exceptions\Billing\PacUnexpectedResponseException;
use App\Exceptions\Billing\PacValidationException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_RESPONSE',
    ]);
});

function fakeInvoiceForResponseTest(): Invoice
{
    $company = \App\Models\Company::factory()->create();
    $invoice = \App\Models\Invoice::factory()->create(['company_id' => $company->id]);
    \App\Models\InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);

    app(\App\Support\Tenant\CurrentTenant::class)->set($company->id);

    return $invoice->fresh(['items']);
}

test('una respuesta exitosa se mapea correctamente a PacInvoiceResult', function () {
    Http::fake(['*' => Http::response([
        'id' => 'inv_789',
        'status' => 'valid',
        'uuid' => 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE',
        'stamped_at' => '2026-07-28T12:00:00Z',
        'cancellation_status' => null,
        'extra_field' => 'algo',
    ], 200)]);

    $result = app(PacProvider::class)->createInvoice(fakeInvoiceForResponseTest());

    expect($result)->toBeInstanceOf(PacInvoiceResult::class)
        ->and($result->externalId)->toBe('inv_789')
        ->and($result->status)->toBe('valid')
        ->and($result->uuid)->toBe('AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE')
        ->and($result->stampedAt)->toBeInstanceOf(\Carbon\CarbonImmutable::class)
        ->and($result->cancellationStatus)->toBeNull()
        ->and($result->rawResponse['extra_field'])->toBe('algo');
});

test('400 y 422 se mapean a PacValidationException', function (int $status) {
    Http::fake(['*' => Http::response(['message' => 'RFC inválido', 'code' => 'invalid_rfc'], $status)]);

    expect(fn () => app(PacProvider::class)->createInvoice(fakeInvoiceForResponseTest()))
        ->toThrow(PacValidationException::class, 'RFC inválido');
})->with([400, 422]);

test('401 y 403 se mapean a PacAuthenticationException', function (int $status) {
    Http::fake(['*' => Http::response(['message' => 'No autorizado', 'code' => 'unauthorized'], $status)]);

    expect(fn () => app(PacProvider::class)->createInvoice(fakeInvoiceForResponseTest()))
        ->toThrow(PacAuthenticationException::class, 'No autorizado');
})->with([401, 403]);

test('429 se mapea a PacRateLimitException', function () {
    Http::fake(['*' => Http::response(['message' => 'Demasiadas solicitudes', 'code' => 'rate_limited'], 429)]);

    expect(fn () => app(PacProvider::class)->createInvoice(fakeInvoiceForResponseTest()))
        ->toThrow(PacRateLimitException::class, 'Demasiadas solicitudes');
});

test('500-599 se mapean a PacUnavailableException', function (int $status) {
    Http::fake(['*' => Http::response(['message' => 'Error interno del PAC'], $status)]);

    expect(fn () => app(PacProvider::class)->createInvoice(fakeInvoiceForResponseTest()))
        ->toThrow(PacUnavailableException::class, 'Error interno del PAC');
})->with([500, 502, 503, 599]);

test('un JSON inesperado (sin id/status) produce PacUnexpectedResponseException', function () {
    Http::fake(['*' => Http::response(['foo' => 'bar'], 200)]);

    expect(fn () => app(PacProvider::class)->createInvoice(fakeInvoiceForResponseTest()))
        ->toThrow(PacUnexpectedResponseException::class);
});

test('un cuerpo no-JSON en una respuesta exitosa también produce PacUnexpectedResponseException', function () {
    Http::fake(['*' => Http::response('<html>no soy json</html>', 200, ['Content-Type' => 'text/html'])]);

    expect(fn () => app(PacProvider::class)->createInvoice(fakeInvoiceForResponseTest()))
        ->toThrow(PacUnexpectedResponseException::class);
});

test('se usa el campo code de Facturapi cuando está disponible', function () {
    Http::fake(['*' => Http::response(['message' => 'Error de validación', 'code' => 'field_invalid_rfc'], 422)]);

    try {
        app(PacProvider::class)->createInvoice(fakeInvoiceForResponseTest());
        $this->fail('Se esperaba PacValidationException');
    } catch (PacValidationException $e) {
        expect($e->pacCode)->toBe('field_invalid_rfc')
            ->and($e->httpStatus)->toBe(422);
    }
});
