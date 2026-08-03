<?php

use App\Contracts\Billing\PacProvider;
use App\Data\Billing\PacInvoiceResult;
use App\Exceptions\Billing\PacAmbiguousInvoiceMatchException;
use App\Exceptions\Billing\PacAuthenticationException;
use App\Exceptions\Billing\PacUnavailableException;
use App\Exceptions\Billing\PacUnexpectedResponseException;
use App\Exceptions\Billing\PacValidationException;
use Illuminate\Support\Facades\Http;

/**
 * FacturapiProvider::findInvoiceByExternalId() (Fase 6.2.2). Endpoint,
 * método y query param confirmados contra docs.facturapi.io/api/: GET
 * /invoices (relativo a baseUrl, que ya incluye /v2) con el query param
 * `external_id` (coincidencia exacta). Respuesta con envoltura de
 * paginación (`page`/`total_pages`/`total_results`/`data`) — se
 * normaliza dentro del adaptador; el dominio solo ve
 * `PacInvoiceResult|null`.
 */
beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_FIND_EXTERNAL_ID',
    ]);
});

test('findInvoiceByExternalId existe en el contrato PacProvider', function () {
    expect(method_exists(PacProvider::class, 'findInvoiceByExternalId'))->toBeTrue();
});

test('usa GET /invoices (no /invoices/{id}) con el query param external_id', function () {
    Http::fake(['*' => Http::response(['page' => 1, 'total_pages' => 1, 'total_results' => 0, 'data' => []], 200)]);

    app(PacProvider::class)->findInvoiceByExternalId('company-4-invoice-125');

    Http::assertSent(function ($request) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        expect($request->method())->toBe('GET')
            ->and($path)->toEndWith('/invoices')
            ->and($request->data()['external_id'] ?? null)->toBe('company-4-invoice-125');

        return true;
    });
});

test('resultado encontrado (un solo elemento en data) se mapea a PacInvoiceResult, no expone la envoltura de paginación', function () {
    Http::fake(['*' => Http::response([
        'page' => 1,
        'total_pages' => 1,
        'total_results' => 1,
        'data' => [[
            'id' => 'inv_single',
            'status' => 'valid',
            'uuid' => 'CCCCCCCC-1111-2222-3333-444444444444',
            'stamp' => ['date' => '2026-08-01T09:00:00Z'],
        ]],
    ], 200)]);

    $result = app(PacProvider::class)->findInvoiceByExternalId('company-4-invoice-125');

    expect($result)->toBeInstanceOf(PacInvoiceResult::class)
        ->and($result->externalId)->toBe('inv_single')
        ->and($result->uuid)->toBe('CCCCCCCC-1111-2222-3333-444444444444')
        ->and($result->stampedAt)->toBeInstanceOf(\Carbon\CarbonImmutable::class)
        ->and($result->rawResponse)->not->toHaveKey('data')
        ->and($result->rawResponse)->not->toHaveKey('page')
        ->and($result->rawResponse)->not->toHaveKey('total_results')
        ->and($result->rawResponse['id'])->toBe('inv_single');
});

test('sin resultados (data vacío), retorna null', function () {
    Http::fake(['*' => Http::response(['page' => 1, 'total_pages' => 1, 'total_results' => 0, 'data' => []], 200)]);

    $result = app(PacProvider::class)->findInvoiceByExternalId('company-4-invoice-999');

    expect($result)->toBeNull();
});

test('múltiples resultados (external_id no es único del lado del PAC) lanzan PacAmbiguousInvoiceMatchException, sin elegir uno en silencio', function () {
    Http::fake(['*' => Http::response([
        'page' => 1,
        'total_pages' => 1,
        'total_results' => 2,
        'data' => [
            ['id' => 'inv_a', 'status' => 'valid'],
            ['id' => 'inv_b', 'status' => 'valid'],
        ],
    ], 200)]);

    try {
        app(PacProvider::class)->findInvoiceByExternalId('company-4-invoice-125');
        test()->fail('Se esperaba PacAmbiguousInvoiceMatchException');
    } catch (PacAmbiguousInvoiceMatchException $e) {
        expect($e->externalId)->toBe('company-4-invoice-125')
            ->and($e->matchCount)->toBe(2);
    }
});

test('total_results > 1 con un solo elemento en data (página parcial) también se trata como ambiguo, nunca se asume el único', function () {
    Http::fake(['*' => Http::response([
        'page' => 1,
        'total_pages' => 1,
        'total_results' => 3,
        'data' => [['id' => 'inv_partial', 'status' => 'valid']],
    ], 200)]);

    expect(fn () => app(PacProvider::class)->findInvoiceByExternalId('company-4-invoice-125'))
        ->toThrow(PacAmbiguousInvoiceMatchException::class);
});

test('400/422 se mapean a PacValidationException', function (int $status) {
    Http::fake(['*' => Http::response(['message' => 'external_id inválido', 'code' => 'invalid_query'], $status)]);

    expect(fn () => app(PacProvider::class)->findInvoiceByExternalId('company-4-invoice-125'))
        ->toThrow(PacValidationException::class);
})->with([400, 422]);

test('401 se mapea a PacAuthenticationException', function () {
    Http::fake(['*' => Http::response(['message' => 'No autorizado'], 401)]);

    expect(fn () => app(PacProvider::class)->findInvoiceByExternalId('company-4-invoice-125'))
        ->toThrow(PacAuthenticationException::class);
});

test('500 se mapea a PacUnavailableException', function () {
    Http::fake(['*' => Http::response(['message' => 'Error interno del PAC'], 500)]);

    expect(fn () => app(PacProvider::class)->findInvoiceByExternalId('company-4-invoice-125'))
        ->toThrow(PacUnavailableException::class);
});

test('una respuesta 200 sin el campo data produce PacUnexpectedResponseException', function () {
    Http::fake(['*' => Http::response(['page' => 1, 'total_results' => 0], 200)]);

    expect(fn () => app(PacProvider::class)->findInvoiceByExternalId('company-4-invoice-125'))
        ->toThrow(PacUnexpectedResponseException::class);
});

test('la API key nunca aparece en el mensaje de una excepción lanzada desde findInvoiceByExternalId', function () {
    config(['services.facturapi.test_key' => 'sk_test_MUY_SECRETA_FIND_998877']);
    Http::fake(['*' => Http::response(['message' => 'No autorizado', 'code' => 'unauthorized'], 401)]);

    try {
        app(PacProvider::class)->findInvoiceByExternalId('company-4-invoice-125');
        test()->fail('Se esperaba PacAuthenticationException');
    } catch (PacAuthenticationException $e) {
        expect($e->getMessage())->not->toContain('sk_test_MUY_SECRETA_FIND_998877')
            ->and((string) $e)->not->toContain('sk_test_MUY_SECRETA_FIND_998877');
    }
});
