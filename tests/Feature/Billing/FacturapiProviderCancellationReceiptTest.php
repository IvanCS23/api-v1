<?php

use App\Contracts\Billing\PacProvider;
use App\Exceptions\Billing\CancellationReceiptUnavailableException;
use App\Exceptions\Billing\PacAuthenticationException;
use App\Exceptions\Billing\PacRateLimitException;
use App\Exceptions\Billing\PacUnavailableException;
use App\Exceptions\Billing\PacValidationException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_CANCELLATION_RECEIPT_PROVIDER',
    ]);

    Http::preventStrayRequests();
});

test('contrato PAC expone descargas especificas para el acuse XML y PDF', function () {
    expect(method_exists(PacProvider::class, 'downloadCancellationReceiptXml'))->toBeTrue()
        ->and(method_exists(PacProvider::class, 'downloadCancellationReceiptPdf'))->toBeTrue();
});

test('acuse XML usa GET al endpoint oficial con Bearer TEST', function () {
    Http::fake(['*' => Http::response('<Acuse/>', 200)]);

    app(PacProvider::class)->downloadCancellationReceiptXml('inv_receipt_xml');

    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && $request->url() === 'https://example-pac.test/v2/invoices/inv_receipt_xml/cancellation_receipt/xml'
        && $request->hasHeader('Authorization', 'Bearer sk_test_CANCELLATION_RECEIPT_PROVIDER'));
});

test('acuse PDF usa GET al endpoint oficial con Bearer TEST', function () {
    Http::fake(['*' => Http::response('%PDF-1.4 receipt', 200)]);

    app(PacProvider::class)->downloadCancellationReceiptPdf('inv_receipt_pdf');

    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && $request->url() === 'https://example-pac.test/v2/invoices/inv_receipt_pdf/cancellation_receipt/pdf'
        && $request->hasHeader('Authorization', 'Bearer sk_test_CANCELLATION_RECEIPT_PROVIDER'));
});

test('devuelve bytes XML exactos sin transformar', function () {
    $bytes = "<?xml version=\"1.0\"?>\n<Acuse>cancelacion exacta \xC3\x91</Acuse>\n";
    Http::fake(['*' => Http::response($bytes, 200, ['Content-Type' => 'application/xml'])]);

    expect(app(PacProvider::class)->downloadCancellationReceiptXml('inv_exact_xml'))->toBe($bytes);
});

test('devuelve bytes PDF exactos sin transformar', function () {
    $bytes = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n\x00\x01 receipt";
    Http::fake(['*' => Http::response($bytes, 200, ['Content-Type' => 'application/pdf'])]);

    expect(app(PacProvider::class)->downloadCancellationReceiptPdf('inv_exact_pdf'))->toBe($bytes);
});

test('provider conserva XML vacio para que el servicio aplique validacion de contenido', function () {
    Http::fake(['*' => Http::response('', 200)]);

    expect(app(PacProvider::class)->downloadCancellationReceiptXml('inv_empty_xml'))->toBe('');
});

test('provider conserva PDF vacio para que el servicio aplique validacion de contenido', function () {
    Http::fake(['*' => Http::response('', 200)]);

    expect(app(PacProvider::class)->downloadCancellationReceiptPdf('inv_empty_pdf'))->toBe('');
});

test('provider conserva PDF sin magic bytes para que el servicio lo rechace', function () {
    Http::fake(['*' => Http::response('{"error":"not pdf"}', 200)]);

    expect(app(PacProvider::class)->downloadCancellationReceiptPdf('inv_bad_pdf'))->toBe('{"error":"not pdf"}');
});

test('content-type inesperado no transforma los bytes en el provider', function () {
    $body = '<html>error upstream</html>';
    Http::fake(['*' => Http::response($body, 200, ['Content-Type' => 'text/html'])]);

    expect(app(PacProvider::class)->downloadCancellationReceiptXml('inv_html'))->toBe($body);
});

test('400 se mapea a PacValidationException', function () {
    Http::fake(['*' => Http::response(['message' => 'Solicitud invalida', 'code' => 'invalid_request'], 400)]);

    expect(fn () => app(PacProvider::class)->downloadCancellationReceiptXml('inv_400'))
        ->toThrow(PacValidationException::class);
});

test('401 se mapea a PacAuthenticationException sin filtrar la key', function () {
    Http::fake(['*' => Http::response(['message' => 'No autorizado'], 401)]);

    try {
        app(PacProvider::class)->downloadCancellationReceiptXml('inv_401');
        test()->fail('Se esperaba PacAuthenticationException.');
    } catch (PacAuthenticationException $e) {
        expect($e->getMessage())->not->toContain('sk_test_CANCELLATION_RECEIPT_PROVIDER');
    }
});

test('429 se mapea a PacRateLimitException', function () {
    Http::fake(['*' => Http::response(['message' => 'Rate limit'], 429)]);

    expect(fn () => app(PacProvider::class)->downloadCancellationReceiptPdf('inv_429'))
        ->toThrow(PacRateLimitException::class);
});

test('500 se mapea a PacUnavailableException', function () {
    Http::fake(['*' => Http::response(['message' => 'PAC unavailable'], 500)]);

    expect(fn () => app(PacProvider::class)->downloadCancellationReceiptPdf('inv_500'))
        ->toThrow(PacUnavailableException::class);
});

test('invoice_cancellation_receipt_unavailable tiene excepcion distinguible', function () {
    Http::fake(['*' => Http::response([
        'message' => 'El acuse de cancelacion no esta disponible.',
        'code' => 'invoice_cancellation_receipt_unavailable',
    ], 400)]);

    try {
        app(PacProvider::class)->downloadCancellationReceiptXml('inv_unavailable');
        test()->fail('Se esperaba CancellationReceiptUnavailableException.');
    } catch (CancellationReceiptUnavailableException $e) {
        expect($e->pacCode)->toBe('invoice_cancellation_receipt_unavailable')
            ->and($e->httpStatus)->toBe(400);
    }
});

test('preventStrayRequests permanece activo y solo permite la llamada simulada', function () {
    Http::fake([
        '*/invoices/inv_strict/cancellation_receipt/xml' => Http::response('<Acuse/>', 200),
    ]);

    app(PacProvider::class)->downloadCancellationReceiptXml('inv_strict');

    Http::assertSentCount(1);
});
