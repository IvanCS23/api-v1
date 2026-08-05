<?php

use App\Contracts\Billing\PacProvider;
use App\Exceptions\Billing\PacAuthenticationException;
use App\Exceptions\Billing\PacRateLimitException;
use App\Exceptions\Billing\PacUnavailableException;
use Illuminate\Support\Facades\Http;

/**
 * CONTRACT (Fase 6.3): FacturapiProvider::downloadXml()/downloadPdf().
 * Endpoints confirmados contra el SDK oficial de Facturapi
 * (facturapi-php/facturapi-node, código fuente y test suite) — ver el
 * reporte de entrega de esta fase para el detalle de la investigación.
 * `$response->body()` se devuelve tal cual, nunca transformado — la
 * validación de contenido vive en DownloadInvoiceArtifactsService, no
 * aquí.
 */
beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_DOWNLOAD_PROVIDER',
    ]);

    if (method_exists(Http::class, 'preventStrayRequests')) {
        Http::preventStrayRequests();
    }
});

test('downloadXml llama GET /invoices/{id}/xml con Authorization Bearer', function () {
    Http::fake(['*' => Http::response('<xml/>', 200)]);

    app(PacProvider::class)->downloadXml('inv_dl_xml_1');

    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && str_ends_with($request->url(), '/invoices/inv_dl_xml_1/xml')
        && $request->hasHeader('Authorization', 'Bearer sk_test_DOWNLOAD_PROVIDER'));
});

test('downloadPdf llama GET /invoices/{id}/pdf con Authorization Bearer', function () {
    Http::fake(['*' => Http::response('%PDF-1.4 fake', 200)]);

    app(PacProvider::class)->downloadPdf('inv_dl_pdf_1');

    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && str_ends_with($request->url(), '/invoices/inv_dl_pdf_1/pdf')
        && $request->hasHeader('Authorization', 'Bearer sk_test_DOWNLOAD_PROVIDER'));
});

test('downloadXml devuelve los bytes exactos del cuerpo de la respuesta, sin transformar', function () {
    $rawXml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<cfdi:Comprobante>contenido exacto Ñ é</cfdi:Comprobante>\n";
    Http::fake(['*' => Http::response($rawXml, 200, ['Content-Type' => 'application/xml'])]);

    $result = app(PacProvider::class)->downloadXml('inv_dl_xml_2');

    expect($result)->toBe($rawXml);
});

test('downloadPdf devuelve los bytes exactos del cuerpo de la respuesta, sin transformar', function () {
    $rawPdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\nbinary garbage \x00\x01\x02 end";
    Http::fake(['*' => Http::response($rawPdf, 200, ['Content-Type' => 'application/pdf'])]);

    $result = app(PacProvider::class)->downloadPdf('inv_dl_pdf_2');

    expect($result)->toBe($rawPdf);
});

test('downloadXml con content-type inesperado (ej. text/html de un error) igual devuelve el body tal cual — la validación de contenido no es responsabilidad del provider', function () {
    Http::fake(['*' => Http::response('<html><body>not xml</body></html>', 200, ['Content-Type' => 'text/html'])]);

    $result = app(PacProvider::class)->downloadXml('inv_dl_xml_html');

    expect($result)->toBe('<html><body>not xml</body></html>');
});

test('downloadXml con respuesta vacía (200 pero body vacío) devuelve cadena vacía sin lanzar', function () {
    Http::fake(['*' => Http::response('', 200)]);

    $result = app(PacProvider::class)->downloadXml('inv_dl_xml_empty');

    expect($result)->toBe('');
});

test('downloadPdf con respuesta vacía (200 pero body vacío) devuelve cadena vacía sin lanzar', function () {
    Http::fake(['*' => Http::response('', 200)]);

    $result = app(PacProvider::class)->downloadPdf('inv_dl_pdf_empty');

    expect($result)->toBe('');
});

test('401 en downloadXml/downloadPdf se mapea a PacAuthenticationException, sin exponer la API key', function () {
    Http::fake(['*' => Http::response(['message' => 'No autorizado', 'code' => 'unauthorized'], 401)]);

    try {
        app(PacProvider::class)->downloadXml('inv_dl_401');
        test()->fail('Se esperaba PacAuthenticationException');
    } catch (PacAuthenticationException $e) {
        expect($e->getMessage())->not->toContain('sk_test_DOWNLOAD_PROVIDER');
    }

    expect(fn () => app(PacProvider::class)->downloadPdf('inv_dl_401'))
        ->toThrow(PacAuthenticationException::class);
});

test('429 en downloadXml/downloadPdf se mapea a PacRateLimitException', function () {
    Http::fake(['*' => Http::response(['message' => 'Demasiadas solicitudes'], 429)]);

    expect(fn () => app(PacProvider::class)->downloadXml('inv_dl_429'))
        ->toThrow(PacRateLimitException::class);
    expect(fn () => app(PacProvider::class)->downloadPdf('inv_dl_429'))
        ->toThrow(PacRateLimitException::class);
});

test('500 en downloadXml/downloadPdf se mapea a PacUnavailableException', function () {
    Http::fake(['*' => Http::response(['message' => 'Error interno del PAC'], 500)]);

    expect(fn () => app(PacProvider::class)->downloadXml('inv_dl_500'))
        ->toThrow(PacUnavailableException::class);
    expect(fn () => app(PacProvider::class)->downloadPdf('inv_dl_500'))
        ->toThrow(PacUnavailableException::class);
});

test('con Http::preventStrayRequests() activo, ninguna llamada no simulada se cuela', function () {
    Http::fake(['*/invoices/inv_dl_strict/xml' => Http::response('<xml/>', 200)]);

    app(PacProvider::class)->downloadXml('inv_dl_strict');

    Http::assertSentCount(1);
});
