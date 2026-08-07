<?php

use App\Data\Billing\CancellationReceiptResult;
use App\Enums\InvoiceStatus;
use App\Exceptions\Billing\CancellationReceiptArtifactMissingException;
use App\Exceptions\Billing\CancellationReceiptUnavailableException;
use App\Exceptions\Billing\PacUnavailableException;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\Billing\DownloadCancellationReceiptService;
use App\Support\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_CANCELLATION_RECEIPT_SERVICE_SECRET',
    ]);

    Http::preventStrayRequests();
    Storage::fake('local');
});

function canceledInvoiceForReceiptTest(Company $company, array $overrides = []): Invoice
{
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Issued,
        'subtotal' => 100,
        'tax_total' => 16,
        'total' => 116,
    ]);

    $invoice->forceFill(array_merge([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_canceled_'.$invoice->id,
        'cfdi_uuid' => '96013e83-154b-4153-8e61-c38b8966e560',
        'pac_status' => 'canceled',
        'cancellation_status' => 'accepted',
        'stamped_at' => now(),
    ], $overrides))->save();

    return $invoice->fresh();
}

function cancellationReceiptXml(string $uuid): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        .'<Acuse xmlns="http://cancelacfd.sat.gob.mx" CodEstatus="201">'
        .'<Folios><UUID>'.$uuid.'</UUID><EstatusUUID>201</EstatusUUID></Folios>'
        .'</Acuse>';
}

function cancellationReceiptPdf(): string
{
    return "%PDF-1.7\n%\xE2\xE3\xCF\xD3\nacuse cancelacion test\n%%EOF";
}

function fakeCancellationReceiptHttp(Invoice $invoice, ?string $xml = null, ?string $pdf = null): void
{
    Http::fake([
        "*/invoices/{$invoice->pac_external_id}/cancellation_receipt/xml" => Http::response(
            $xml ?? cancellationReceiptXml((string) $invoice->cfdi_uuid),
            200,
            ['Content-Type' => 'application/xml'],
        ),
        "*/invoices/{$invoice->pac_external_id}/cancellation_receipt/pdf" => Http::response(
            $pdf ?? cancellationReceiptPdf(),
            200,
            ['Content-Type' => 'application/pdf'],
        ),
    ]);
}

test('tenant correcto y cancelacion accepted+canceled permiten descargar el acuse', function () {
    $company = Company::factory()->create();
    $invoice = canceledInvoiceForReceiptTest($company);
    app(CurrentTenant::class)->set($company->id);
    fakeCancellationReceiptHttp($invoice);

    $result = app(DownloadCancellationReceiptService::class)->download($invoice);

    expect($result)->toBeInstanceOf(CancellationReceiptResult::class)
        ->and($invoice->fresh()->cancellation_receipt_status)->toBe('stored');
});

test('sin tenant rechaza antes de HTTP', function () {
    $invoice = canceledInvoiceForReceiptTest(Company::factory()->create());
    Http::fake();

    expect(fn () => app(DownloadCancellationReceiptService::class)->download($invoice))
        ->toThrow(ModelNotFoundException::class);
    Http::assertNothingSent();
});

test('tenant ajeno rechaza antes de HTTP', function () {
    $foreign = canceledInvoiceForReceiptTest(Company::factory()->create());
    app(CurrentTenant::class)->set(Company::factory()->create()->id);
    Http::fake();

    expect(fn () => app(DownloadCancellationReceiptService::class)->download($foreign))
        ->toThrow(ModelNotFoundException::class);
    Http::assertNothingSent();
});

test('falta pac_external_id rechaza antes de HTTP', function () {
    $company = Company::factory()->create();
    $invoice = canceledInvoiceForReceiptTest($company, ['pac_external_id' => null]);
    app(CurrentTenant::class)->set($company->id);
    Http::fake();

    expect(fn () => app(DownloadCancellationReceiptService::class)->download($invoice))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

test('falta cfdi_uuid rechaza antes de HTTP', function () {
    $company = Company::factory()->create();
    $invoice = canceledInvoiceForReceiptTest($company, ['cfdi_uuid' => null]);
    app(CurrentTenant::class)->set($company->id);
    Http::fake();

    expect(fn () => app(DownloadCancellationReceiptService::class)->download($invoice))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

test('pac_status distinto de canceled rechaza antes de HTTP', function () {
    $company = Company::factory()->create();
    $invoice = canceledInvoiceForReceiptTest($company, ['pac_status' => 'valid']);
    app(CurrentTenant::class)->set($company->id);
    Http::fake();

    expect(fn () => app(DownloadCancellationReceiptService::class)->download($invoice))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

test('cancellation_status distinto de accepted rechaza antes de HTTP', function (?string $status) {
    $company = Company::factory()->create();
    $invoice = canceledInvoiceForReceiptTest($company, ['cancellation_status' => $status]);
    app(CurrentTenant::class)->set($company->id);
    Http::fake();

    expect(fn () => app(DownloadCancellationReceiptService::class)->download($invoice))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
})->with([null, 'none', 'pending', 'verifying', 'rejected', 'expired']);

test('una descarga de acuse ya pending no inicia otra llamada HTTP', function () {
    $company = Company::factory()->create();
    $invoice = canceledInvoiceForReceiptTest($company, ['cancellation_receipt_status' => 'pending']);
    app(CurrentTenant::class)->set($company->id);
    Http::fake();

    expect(fn () => app(DownloadCancellationReceiptService::class)->download($invoice))
        ->toThrow(RuntimeException::class, 'descarga de acuse en curso');

    Http::assertNothingSent();
});

test('pending y verifying explican que la cancelacion sigue en curso', function (string $status) {
    $company = Company::factory()->create();
    $invoice = canceledInvoiceForReceiptTest($company, ['cancellation_status' => $status]);
    app(CurrentTenant::class)->set($company->id);
    Http::fake();

    expect(fn () => app(DownloadCancellationReceiptService::class)->download($invoice))
        ->toThrow(RuntimeException::class, 'sigue en curso');
})->with(['pending', 'verifying']);

test('XML SAT valido y PDF valido se almacenan byte por byte en rutas privadas por tenant', function () {
    Storage::fake('public');
    $company = Company::factory()->create();
    $invoice = canceledInvoiceForReceiptTest($company);
    app(CurrentTenant::class)->set($company->id);
    $xml = cancellationReceiptXml(strtoupper((string) $invoice->cfdi_uuid));
    $pdf = cancellationReceiptPdf();
    fakeCancellationReceiptHttp($invoice, $xml, $pdf);

    $result = app(DownloadCancellationReceiptService::class)->download($invoice);

    expect($result->xmlPath)->toBe("cancellation-receipts/{$company->id}/{$invoice->id}/{$invoice->cfdi_uuid}.xml")
        ->and($result->pdfPath)->toBe("cancellation-receipts/{$company->id}/{$invoice->id}/{$invoice->cfdi_uuid}.pdf")
        ->and(Storage::disk('local')->get($result->xmlPath))->toBe($xml)
        ->and(Storage::disk('local')->get($result->pdfPath))->toBe($pdf)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('XML mal formado se rechaza antes de escribir archivos', function () {
    $company = Company::factory()->create();
    $invoice = canceledInvoiceForReceiptTest($company);
    app(CurrentTenant::class)->set($company->id);
    fakeCancellationReceiptHttp($invoice, '<Acuse><Folios>');

    expect(fn () => app(DownloadCancellationReceiptService::class)->download($invoice))->toThrow(RuntimeException::class);
    expect(Storage::disk('local')->allFiles())->toBe([])
        ->and($invoice->fresh()->cancellation_receipt_status)->toBe('reconciliation_required');
});

test('XML vacio se rechaza antes de solicitar el PDF o escribir archivos', function () {
    $company = Company::factory()->create();
    $invoice = canceledInvoiceForReceiptTest($company);
    app(CurrentTenant::class)->set($company->id);
    fakeCancellationReceiptHttp($invoice, '');

    expect(fn () => app(DownloadCancellationReceiptService::class)->download($invoice))->toThrow(RuntimeException::class);
    expect(Storage::disk('local')->allFiles())->toBe([]);
    Http::assertSentCount(1);
});

test('XML que no es un Acuse SAT se rechaza aunque sea XML bien formado', function () {
    $company = Company::factory()->create();
    $invoice = canceledInvoiceForReceiptTest($company);
    app(CurrentTenant::class)->set($company->id);
    fakeCancellationReceiptHttp($invoice, '<html><body>error</body></html>');

    expect(fn () => app(DownloadCancellationReceiptService::class)->download($invoice))->toThrow(RuntimeException::class);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('XXE y DOCTYPE se rechazan sin resolver contenido externo', function () {
    $company = Company::factory()->create();
    $invoice = canceledInvoiceForReceiptTest($company);
    app(CurrentTenant::class)->set($company->id);
    $secret = tempnam(sys_get_temp_dir(), 'receipt_xxe_');
    file_put_contents($secret, 'SECRETO_XXE_ACUSE');
    $uri = 'file:///'.str_replace('\\', '/', $secret);
    $xml = '<?xml version="1.0"?><!DOCTYPE Acuse [<!ENTITY xxe SYSTEM "'.$uri.'">]>'
        .'<Acuse><Folios><UUID>&xxe;</UUID></Folios></Acuse>';
    fakeCancellationReceiptHttp($invoice, $xml);

    try {
        app(DownloadCancellationReceiptService::class)->download($invoice);
    } catch (Throwable $e) {
        expect($e->getMessage())->not->toContain('SECRETO_XXE_ACUSE');
    } finally {
        @unlink($secret);
    }

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('UUID del Acuse debe coincidir con Invoice cfdi_uuid', function () {
    $company = Company::factory()->create();
    $invoice = canceledInvoiceForReceiptTest($company);
    app(CurrentTenant::class)->set($company->id);
    fakeCancellationReceiptHttp($invoice, cancellationReceiptXml('00000000-0000-0000-0000-000000000000'));

    expect(fn () => app(DownloadCancellationReceiptService::class)->download($invoice))
        ->toThrow(RuntimeException::class, 'UUID del acuse XML no coincide');
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('PDF vacio o sin encabezado PDF se rechaza sin dejar XML parcial', function (string $pdf) {
    $company = Company::factory()->create();
    $invoice = canceledInvoiceForReceiptTest($company);
    app(CurrentTenant::class)->set($company->id);
    fakeCancellationReceiptHttp($invoice, null, $pdf);

    expect(fn () => app(DownloadCancellationReceiptService::class)->download($invoice))->toThrow(RuntimeException::class);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with(['', '{"error":"receipt unavailable"}', '<html>error</html>']);

test('hashes y tamanos se calculan sobre bytes exactos y se persisten', function () {
    $company = Company::factory()->create();
    $invoice = canceledInvoiceForReceiptTest($company);
    app(CurrentTenant::class)->set($company->id);
    $xml = cancellationReceiptXml((string) $invoice->cfdi_uuid);
    $pdf = cancellationReceiptPdf();
    fakeCancellationReceiptHttp($invoice, $xml, $pdf);

    $result = app(DownloadCancellationReceiptService::class)->download($invoice);
    $fresh = $invoice->fresh();

    expect($result->xmlHash)->toBe(hash('sha256', $xml))
        ->and($result->pdfHash)->toBe(hash('sha256', $pdf))
        ->and($result->xmlSize)->toBe(strlen($xml))
        ->and($result->pdfSize)->toBe(strlen($pdf))
        ->and($fresh->cancellation_receipt_xml_sha256)->toBe($result->xmlHash)
        ->and($fresh->cancellation_receipt_pdf_sha256)->toBe($result->pdfHash)
        ->and($fresh->cancellation_receipt_xml_size)->toBe(strlen($xml))
        ->and($fresh->cancellation_receipt_pdf_size)->toBe(strlen($pdf))
        ->and($fresh->cancellation_receipt_downloaded_at)->toBeInstanceOf(CarbonImmutable::class);
});

test('segunda ejecucion stored integra devuelve metadata y hace cero HTTP adicional', function () {
    $company = Company::factory()->create();
    $invoice = canceledInvoiceForReceiptTest($company);
    app(CurrentTenant::class)->set($company->id);
    fakeCancellationReceiptHttp($invoice);

    $first = app(DownloadCancellationReceiptService::class)->download($invoice);
    expect(Http::recorded())->toHaveCount(2);

    Http::fake();
    $second = app(DownloadCancellationReceiptService::class)->download($invoice->fresh());

    Http::assertNothingSent();
    expect($second->xmlHash)->toBe($first->xmlHash)
        ->and($second->pdfHash)->toBe($first->pdfHash);
});

test('archivo XML local faltante marca reconciliation_required sin redescargar', function () {
    $company = Company::factory()->create();
    $invoice = canceledInvoiceForReceiptTest($company);
    app(CurrentTenant::class)->set($company->id);
    fakeCancellationReceiptHttp($invoice);
    $stored = app(DownloadCancellationReceiptService::class)->download($invoice);
    Storage::disk('local')->delete($stored->xmlPath);
    Http::fake();

    expect(fn () => app(DownloadCancellationReceiptService::class)->download($invoice->fresh()))
        ->toThrow(CancellationReceiptArtifactMissingException::class);

    Http::assertNothingSent();
    expect($invoice->fresh()->cancellation_receipt_status)->toBe('reconciliation_required');
});

test('hash local distinto marca reconciliation_required sin redescargar', function () {
    $company = Company::factory()->create();
    $invoice = canceledInvoiceForReceiptTest($company);
    app(CurrentTenant::class)->set($company->id);
    fakeCancellationReceiptHttp($invoice);
    $stored = app(DownloadCancellationReceiptService::class)->download($invoice);
    Storage::disk('local')->put($stored->pdfPath, '%PDF-1.4 corrupted');
    Http::fake();

    expect(fn () => app(DownloadCancellationReceiptService::class)->download($invoice->fresh()))
        ->toThrow(CancellationReceiptArtifactMissingException::class);

    Http::assertNothingSent();
    expect($invoice->fresh()->cancellation_receipt_status)->toBe('reconciliation_required');
});

test('fallo DB posterior al move compensa borrando ambos archivos finales best-effort', function () {
    $company = Company::factory()->create();
    $invoice = canceledInvoiceForReceiptTest($company);
    app(CurrentTenant::class)->set($company->id);
    fakeCancellationReceiptHttp($invoice);

    DB::listen(function (QueryExecuted $query): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'update')
            && in_array('stored', $query->bindings, true)) {
            throw new RuntimeException('Fallo DB simulado despues del move.');
        }
    });

    expect(fn () => app(DownloadCancellationReceiptService::class)->download($invoice))
        ->toThrow(RuntimeException::class, 'Fallo DB simulado');

    expect(Storage::disk('local')->allFiles())->toBe([])
        ->and($invoice->fresh()->cancellation_receipt_status)->toBe('reconciliation_required');
});

test('error PAC no deja archivos y conserva una clasificacion trazable', function () {
    $company = Company::factory()->create();
    $invoice = canceledInvoiceForReceiptTest($company);
    app(CurrentTenant::class)->set($company->id);
    Http::fake([
        "*/invoices/{$invoice->pac_external_id}/cancellation_receipt/xml" => Http::response(['message' => 'PAC caido'], 500),
    ]);

    expect(fn () => app(DownloadCancellationReceiptService::class)->download($invoice))
        ->toThrow(PacUnavailableException::class);

    expect(Storage::disk('local')->allFiles())->toBe([])
        ->and($invoice->fresh()->cancellation_receipt_status)->toBe('reconciliation_required');
});

test('fallo al persistir diagnostico no oculta la excepcion PAC original y aun emite el evento', function () {
    Log::spy();
    $company = Company::factory()->create();
    $invoice = canceledInvoiceForReceiptTest($company);
    app(CurrentTenant::class)->set($company->id);
    Http::fake([
        "*/invoices/{$invoice->pac_external_id}/cancellation_receipt/xml" => Http::response([
            'message' => 'PAC caido',
            'code' => 'internal_error',
        ], 500),
    ]);

    DB::listen(function (QueryExecuted $query): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'update')
            && in_array('reconciliation_required', $query->bindings, true)) {
            throw new RuntimeException('Fallo simulado al persistir diagnostico.');
        }
    });

    expect(fn () => app(DownloadCancellationReceiptService::class)->download($invoice))
        ->toThrow(PacUnavailableException::class);

    expect($invoice->fresh()->cancellation_receipt_status)->toBe('pending');

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $event, array $context): bool => $event === 'billing.invoice.cancellation_receipt_attempt'
            && $context['invoice_id'] === $invoice->id
            && $context['cancellation_receipt_status'] === 'reconciliation_required'
            && $context['pac_error_code'] === 'internal_error'
            && $context['diagnostic_persistence_failed'] === true)
        ->once();
});

test('receipt unavailable marca reconciliation_required y usa excepcion de dominio', function () {
    $company = Company::factory()->create();
    $invoice = canceledInvoiceForReceiptTest($company);
    app(CurrentTenant::class)->set($company->id);
    Http::fake([
        "*/invoices/{$invoice->pac_external_id}/cancellation_receipt/xml" => Http::response([
            'message' => 'Aun no disponible',
            'code' => 'invoice_cancellation_receipt_unavailable',
        ], 400),
    ]);

    expect(fn () => app(DownloadCancellationReceiptService::class)->download($invoice))
        ->toThrow(CancellationReceiptUnavailableException::class);

    $fresh = $invoice->fresh();
    expect($fresh->cancellation_receipt_status)->toBe('reconciliation_required')
        ->and($fresh->cancellation_receipt_last_error)->toContain('invoice_cancellation_receipt_unavailable');
});

test('logs y last_error no filtran bytes, API key ni Authorization', function () {
    Log::spy();
    $company = Company::factory()->create();
    $invoice = canceledInvoiceForReceiptTest($company);
    app(CurrentTenant::class)->set($company->id);
    Http::fake([
        "*/invoices/{$invoice->pac_external_id}/cancellation_receipt/xml" => Http::response([
            'message' => 'Bearer sk_test_CANCELLATION_RECEIPT_SERVICE_SECRET Authorization',
            'code' => 'invalid_request',
        ], 400),
    ]);

    try {
        app(DownloadCancellationReceiptService::class)->download($invoice);
    } catch (Throwable) {
    }

    $error = (string) $invoice->fresh()->cancellation_receipt_last_error;
    expect($error)->not->toContain('sk_test_CANCELLATION_RECEIPT_SERVICE_SECRET')
        ->and($error)->not->toContain('Authorization')
        ->and($error)->not->toContain('Bearer');

    Log::shouldHaveReceived('info')->withArgs(function (string $event, array $context): bool {
        if ($event !== 'billing.invoice.cancellation_receipt_attempt') {
            return false;
        }

        $serialized = json_encode($context);
        expect($serialized)->not->toContain('sk_test_CANCELLATION_RECEIPT_SERVICE_SECRET')
            ->and($serialized)->not->toContain('<Acuse')
            ->and($serialized)->not->toContain('%PDF-')
            ->and($context)->not->toHaveKey('xml_path');

        return true;
    })->atLeast()->once();
});

test('no modifica artifacts CFDI originales ni estado fiscal, UUID o totales', function () {
    $company = Company::factory()->create();
    $invoice = canceledInvoiceForReceiptTest($company, [
        'cfdi_xml_path' => 'cfdi/original.xml',
        'cfdi_pdf_path' => 'cfdi/original.pdf',
        'cfdi_xml_sha256' => str_repeat('a', 64),
        'cfdi_pdf_sha256' => str_repeat('b', 64),
        'cfdi_artifacts_status' => 'stored',
    ]);
    app(CurrentTenant::class)->set($company->id);
    fakeCancellationReceiptHttp($invoice);

    app(DownloadCancellationReceiptService::class)->download($invoice);
    $fresh = $invoice->fresh();

    expect($fresh->cfdi_xml_path)->toBe('cfdi/original.xml')
        ->and($fresh->cfdi_pdf_path)->toBe('cfdi/original.pdf')
        ->and($fresh->cfdi_xml_sha256)->toBe(str_repeat('a', 64))
        ->and($fresh->cfdi_pdf_sha256)->toBe(str_repeat('b', 64))
        ->and($fresh->cfdi_artifacts_status)->toBe('stored')
        ->and($fresh->pac_status)->toBe('canceled')
        ->and($fresh->cancellation_status)->toBe('accepted')
        ->and($fresh->cfdi_uuid)->toBe($invoice->cfdi_uuid)
        ->and($fresh->subtotal)->toBe('100.00')
        ->and($fresh->tax_total)->toBe('16.00')
        ->and($fresh->total)->toBe('116.00');
});

test('solo llama descargas del acuse: nunca cancel, stamp, create ni artifacts CFDI originales', function () {
    $company = Company::factory()->create();
    $invoice = canceledInvoiceForReceiptTest($company);
    app(CurrentTenant::class)->set($company->id);
    fakeCancellationReceiptHttp($invoice);

    app(DownloadCancellationReceiptService::class)->download($invoice);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && str_contains($request->url(), '/cancellation_receipt/'));
    foreach (Http::recorded() as [$request]) {
        expect($request->method())->toBe('GET')
            ->and($request->url())->not->toEndWith('/stamp')
            ->and($request->url())->not->toMatch('#/invoices/[^/]+/(xml|pdf)$#');
    }
});
