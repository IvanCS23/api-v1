<?php

use App\Data\Billing\InvoiceArtifactsResult;
use App\Enums\InvoicePacEventType;
use App\Enums\InvoiceStatus;
use App\Exceptions\Billing\CfdiArtifactMismatchException;
use App\Exceptions\Billing\CfdiArtifactMissingException;
use App\Exceptions\Billing\PacUnavailableException;
use App\Exceptions\Billing\PacValidationException;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\Billing\DownloadInvoiceArtifactsService;
use App\Support\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * DownloadInvoiceArtifactsService (Fase 6.3): descarga (una sola vez),
 * valida y almacena privadamente el XML/PDF de un CFDI YA TIMBRADO.
 * Nunca importa FacturapiProvider — solo PacProvider + Storage. Todas
 * las pruebas usan Http::fake() + Storage::fake('local'); cero HTTP
 * real, cero escritura fuera del disk fake de test.
 */
beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_ARTIFACTS_SERVICE',
    ]);

    Storage::fake('local');

    if (method_exists(Http::class, 'preventStrayRequests')) {
        Http::preventStrayRequests();
    }
});

function stampedInvoiceForArtifactsTest(Company $company, array $overrides = []): Invoice
{
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    $invoice->forceFill(array_merge([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_stamped_'.$invoice->id,
        'cfdi_uuid' => 'fced601d-c4f6-4ce7-8f05-f3d38de530f9',
        'pac_status' => 'valid',
        'pac_issue_status' => 'succeeded',
        'stamped_at' => now(),
    ], $overrides))->save();

    return $invoice->fresh();
}

function fakeCfdiXml(string $uuid): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        .'<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" Version="4.0" Total="116.00">'
        .'<cfdi:Complemento>'
        .'<tfd:TimbreFiscalDigital xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" Version="1.1" UUID="'.$uuid.'" SelloCFD="SELLO_FAKE"/>'
        .'</cfdi:Complemento>'
        .'</cfdi:Comprobante>';
}

function fakeCfdiPdf(): string
{
    return "%PDF-1.4\n%\xE2\xE3\xCF\xD3\nfake pdf body for tests\n%%EOF";
}

function fakeArtifactsHttp(Invoice $invoice, ?string $xml = null, ?string $pdf = null): void
{
    Http::fake([
        "*/invoices/{$invoice->pac_external_id}/xml" => Http::response($xml ?? fakeCfdiXml($invoice->cfdi_uuid), 200),
        "*/invoices/{$invoice->pac_external_id}/pdf" => Http::response($pdf ?? fakeCfdiPdf(), 200),
    ]);
}

// ==================== PRECONDICIONES ====================

test('Invoice draft/pending (sin pac_status=valid) es rechazada sin HTTP', function (?string $pacStatus) {
    $company = Company::factory()->create();
    $invoice = stampedInvoiceForArtifactsTest($company, ['pac_status' => $pacStatus, 'cfdi_uuid' => null, 'stamped_at' => null]);
    app(CurrentTenant::class)->set($company->id);

    Http::fake();

    expect(fn () => app(DownloadInvoiceArtifactsService::class)->download($invoice))
        ->toThrow(RuntimeException::class);

    Http::assertNothingSent();
})->with([null, 'pending']);

test('falta pac_external_id: rechazada sin HTTP', function () {
    $company = Company::factory()->create();
    $invoice = stampedInvoiceForArtifactsTest($company, ['pac_external_id' => null]);
    app(CurrentTenant::class)->set($company->id);

    Http::fake();

    expect(fn () => app(DownloadInvoiceArtifactsService::class)->download($invoice))
        ->toThrow(RuntimeException::class);

    Http::assertNothingSent();
});

test('falta cfdi_uuid: rechazada sin HTTP', function () {
    $company = Company::factory()->create();
    $invoice = stampedInvoiceForArtifactsTest($company, ['cfdi_uuid' => null]);
    app(CurrentTenant::class)->set($company->id);

    Http::fake();

    expect(fn () => app(DownloadInvoiceArtifactsService::class)->download($invoice))
        ->toThrow(RuntimeException::class);

    Http::assertNothingSent();
});

test('multi-tenant: no se pueden descargar artifacts de una Invoice de otra empresa', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $foreignInvoice = stampedInvoiceForArtifactsTest($companyB);

    app(CurrentTenant::class)->set($companyA->id);

    Http::fake();

    expect(fn () => app(DownloadInvoiceArtifactsService::class)->download($foreignInvoice))
        ->toThrow(ModelNotFoundException::class);

    Http::assertNothingSent();
});

// ==================== ÉXITO ====================

test('Invoice válida con pac_status=valid: descarga, valida y almacena XML/PDF privadamente', function () {
    $company = Company::factory()->create();
    $invoice = stampedInvoiceForArtifactsTest($company);
    app(CurrentTenant::class)->set($company->id);

    fakeArtifactsHttp($invoice);

    $result = app(DownloadInvoiceArtifactsService::class)->download($invoice);

    expect($result)->toBeInstanceOf(InvoiceArtifactsResult::class)
        ->and($result->xmlPath)->toBe("cfdi/{$company->id}/{$invoice->id}/{$invoice->cfdi_uuid}.xml")
        ->and($result->pdfPath)->toBe("cfdi/{$company->id}/{$invoice->id}/{$invoice->cfdi_uuid}.pdf");

    Storage::disk('local')->assertExists($result->xmlPath);
    Storage::disk('local')->assertExists($result->pdfPath);

    $fresh = $invoice->fresh();
    expect($fresh->cfdi_artifacts_status)->toBe('stored')
        ->and($fresh->cfdi_xml_path)->toBe($result->xmlPath)
        ->and($fresh->cfdi_pdf_path)->toBe($result->pdfPath)
        ->and($fresh->cfdi_artifacts_downloaded_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($fresh->cfdi_artifacts_last_error)->toBeNull()
        ->and($fresh->pacEvents()->pluck('event_type')->all())->toBe([
            InvoicePacEventType::ArtifactsStored,
        ]);
});

test('el XML almacenado es byte-for-byte el recibido del PAC (nunca reserializado)', function () {
    $company = Company::factory()->create();
    $invoice = stampedInvoiceForArtifactsTest($company);
    app(CurrentTenant::class)->set($company->id);

    $xml = fakeCfdiXml($invoice->cfdi_uuid);
    fakeArtifactsHttp($invoice, $xml);

    $result = app(DownloadInvoiceArtifactsService::class)->download($invoice);

    expect(Storage::disk('local')->get($result->xmlPath))->toBe($xml);
});

test('hashes SHA-256 y tamaños se calculan sobre los bytes exactos descargados', function () {
    $company = Company::factory()->create();
    $invoice = stampedInvoiceForArtifactsTest($company);
    app(CurrentTenant::class)->set($company->id);

    $xml = fakeCfdiXml($invoice->cfdi_uuid);
    $pdf = fakeCfdiPdf();
    fakeArtifactsHttp($invoice, $xml, $pdf);

    $result = app(DownloadInvoiceArtifactsService::class)->download($invoice);

    expect($result->xmlHash)->toBe(hash('sha256', $xml))
        ->and($result->pdfHash)->toBe(hash('sha256', $pdf))
        ->and($result->xmlSize)->toBe(strlen($xml))
        ->and($result->pdfSize)->toBe(strlen($pdf));

    $fresh = $invoice->fresh();
    expect($fresh->cfdi_xml_sha256)->toBe($result->xmlHash)
        ->and($fresh->cfdi_pdf_sha256)->toBe($result->pdfHash)
        ->and($fresh->cfdi_xml_size)->toBe($result->xmlSize)
        ->and($fresh->cfdi_pdf_size)->toBe($result->pdfSize);
});

test('la ruta incluye company_id/invoice_id, aislada por tenant — nunca datos del request', function () {
    $company = Company::factory()->create();
    $invoice = stampedInvoiceForArtifactsTest($company);
    app(CurrentTenant::class)->set($company->id);

    fakeArtifactsHttp($invoice);

    $result = app(DownloadInvoiceArtifactsService::class)->download($invoice);

    expect($result->xmlPath)->toStartWith("cfdi/{$company->id}/{$invoice->id}/");
});

test('nunca escribe en el disk public: solo el disk privado local', function () {
    Storage::fake('public');

    $company = Company::factory()->create();
    $invoice = stampedInvoiceForArtifactsTest($company);
    app(CurrentTenant::class)->set($company->id);

    fakeArtifactsHttp($invoice);

    app(DownloadInvoiceArtifactsService::class)->download($invoice);

    expect(Storage::disk('public')->allFiles())->toBe([]);
});

// ==================== VALIDACIÓN UUID ====================

test('el UUID del XML coincide con Invoice::cfdi_uuid, ignorando mayúsculas/minúsculas', function () {
    $company = Company::factory()->create();
    $invoice = stampedInvoiceForArtifactsTest($company, ['cfdi_uuid' => 'fced601d-c4f6-4ce7-8f05-f3d38de530f9']);
    app(CurrentTenant::class)->set($company->id);

    // El XML remoto trae el UUID en MAYÚSCULAS — sigue siendo el mismo CFDI.
    fakeArtifactsHttp($invoice, fakeCfdiXml('FCED601D-C4F6-4CE7-8F05-F3D38DE530F9'));

    $result = app(DownloadInvoiceArtifactsService::class)->download($invoice);

    expect($result)->toBeInstanceOf(InvoiceArtifactsResult::class);
});

test('si el UUID del XML NO coincide, lanza CfdiArtifactMismatchException y NO marca los artifacts como stored', function () {
    $company = Company::factory()->create();
    $invoice = stampedInvoiceForArtifactsTest($company, ['cfdi_uuid' => 'fced601d-c4f6-4ce7-8f05-f3d38de530f9']);
    app(CurrentTenant::class)->set($company->id);

    fakeArtifactsHttp($invoice, fakeCfdiXml('00000000-0000-0000-0000-000000000000'));

    expect(fn () => app(DownloadInvoiceArtifactsService::class)->download($invoice))
        ->toThrow(CfdiArtifactMismatchException::class);

    $fresh = $invoice->fresh();
    expect($fresh->cfdi_artifacts_status)->not->toBe('stored')
        ->and($fresh->cfdi_xml_path)->toBeNull()
        ->and($fresh->cfdi_uuid)->toBe('fced601d-c4f6-4ce7-8f05-f3d38de530f9'); // nunca se reemplaza local
});

test('XML vacío: rechazado, no se marca como stored, no queda ningún archivo escrito', function () {
    $company = Company::factory()->create();
    $invoice = stampedInvoiceForArtifactsTest($company);
    app(CurrentTenant::class)->set($company->id);

    fakeArtifactsHttp($invoice, '');

    expect(fn () => app(DownloadInvoiceArtifactsService::class)->download($invoice))
        ->toThrow(RuntimeException::class);

    expect($invoice->fresh()->cfdi_artifacts_status)->not->toBe('stored');
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('XML mal formado: rechazado, no se marca como stored', function () {
    $company = Company::factory()->create();
    $invoice = stampedInvoiceForArtifactsTest($company);
    app(CurrentTenant::class)->set($company->id);

    fakeArtifactsHttp($invoice, '<cfdi:Comprobante><sin cerrar');

    expect(fn () => app(DownloadInvoiceArtifactsService::class)->download($invoice))
        ->toThrow(RuntimeException::class);

    expect($invoice->fresh()->cfdi_artifacts_status)->not->toBe('stored');
});

test('un intento de XXE (entidad externa apuntando a un archivo local) nunca resuelve ni filtra el contenido externo', function () {
    $company = Company::factory()->create();
    $invoice = stampedInvoiceForArtifactsTest($company);
    app(CurrentTenant::class)->set($company->id);

    $secretPath = tempnam(sys_get_temp_dir(), 'xxe_secret_');
    file_put_contents($secretPath, 'SECRETO_QUE_NUNCA_DEBE_APARECER_EN_NINGUN_LADO');
    $fileUri = 'file:///'.str_replace('\\', '/', $secretPath);

    $maliciousXml = '<?xml version="1.0"?>'
        .'<!DOCTYPE r [<!ENTITY xxe SYSTEM "'.$fileUri.'">]>'
        .'<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4">'
        .'<cfdi:Complemento><tfd:TimbreFiscalDigital xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" UUID="&xxe;"/></cfdi:Complemento>'
        .'</cfdi:Comprobante>';

    fakeArtifactsHttp($invoice, $maliciousXml);

    try {
        app(DownloadInvoiceArtifactsService::class)->download($invoice);
    } catch (Throwable $e) {
        expect($e->getMessage())->not->toContain('SECRETO_QUE_NUNCA_DEBE_APARECER_EN_NINGUN_LADO');
    }

    foreach (Storage::disk('local')->allFiles() as $file) {
        expect(Storage::disk('local')->get($file))->not->toContain('SECRETO_QUE_NUNCA_DEBE_APARECER_EN_NINGUN_LADO');
    }

    expect($invoice->fresh()->cfdi_artifacts_status)->not->toBe('stored');

    @unlink($secretPath);
});

// ==================== VALIDACIÓN PDF ====================

test('PDF inválido (sin encabezado %PDF-, ej. JSON de error): rechazado, no se marca como stored, ningún archivo huérfano en disco', function () {
    $company = Company::factory()->create();
    $invoice = stampedInvoiceForArtifactsTest($company);
    app(CurrentTenant::class)->set($company->id);

    fakeArtifactsHttp($invoice, fakeCfdiXml($invoice->cfdi_uuid), '{"error":"not a pdf"}');

    expect(fn () => app(DownloadInvoiceArtifactsService::class)->download($invoice))
        ->toThrow(RuntimeException::class);

    $fresh = $invoice->fresh();
    expect($fresh->cfdi_artifacts_status)->not->toBe('stored')
        ->and($fresh->cfdi_xml_path)->toBeNull();

    // El XML se descarga y valida ANTES que el PDF: si el PDF falla,
    // nunca se llegó a escribir nada en disco (validar ambos en memoria
    // antes de cualquier escritura) — nunca queda un .xml huérfano.
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('PDF vacío: rechazado, no se marca como stored', function () {
    $company = Company::factory()->create();
    $invoice = stampedInvoiceForArtifactsTest($company);
    app(CurrentTenant::class)->set($company->id);

    fakeArtifactsHttp($invoice, fakeCfdiXml($invoice->cfdi_uuid), '');

    expect(fn () => app(DownloadInvoiceArtifactsService::class)->download($invoice))
        ->toThrow(RuntimeException::class);

    expect($invoice->fresh()->cfdi_artifacts_status)->not->toBe('stored');
});

// ==================== IDEMPOTENCIA ====================

test('segunda ejecución con artifacts ya stored: no vuelve a descargar (cero HTTP), devuelve los existentes', function () {
    $company = Company::factory()->create();
    $invoice = stampedInvoiceForArtifactsTest($company);
    app(CurrentTenant::class)->set($company->id);

    fakeArtifactsHttp($invoice);

    $first = app(DownloadInvoiceArtifactsService::class)->download($invoice);

    Http::fake(); // cualquier llamada aquí en adelante sería inesperada

    $second = app(DownloadInvoiceArtifactsService::class)->download($invoice->fresh());

    Http::assertNothingSent();
    expect($second->xmlHash)->toBe($first->xmlHash)
        ->and($second->pdfHash)->toBe($first->pdfHash)
        ->and($second->xmlPath)->toBe($first->xmlPath);
});

test('forceRefresh=true SÍ vuelve a descargar aunque ya existan artifacts stored', function () {
    $company = Company::factory()->create();
    $invoice = stampedInvoiceForArtifactsTest($company);
    app(CurrentTenant::class)->set($company->id);

    // Un solo Http::fake() basta: el patrón de URL responde a cuantas
    // peticiones coincidan, no se "consume" — un segundo Http::fake()
    // reiniciaría el contador de peticiones grabadas.
    fakeArtifactsHttp($invoice);

    app(DownloadInvoiceArtifactsService::class)->download($invoice);
    app(DownloadInvoiceArtifactsService::class)->download($invoice->fresh(), forceRefresh: true);

    Http::assertSentCount(4); // 2 llamadas (xml+pdf) por cada download()
});

test('si cfdi_artifacts_status ya está pending localmente, una segunda llamada no reintenta', function () {
    $company = Company::factory()->create();
    $invoice = stampedInvoiceForArtifactsTest($company, ['cfdi_artifacts_status' => 'pending']);
    app(CurrentTenant::class)->set($company->id);

    Http::fake();

    expect(fn () => app(DownloadInvoiceArtifactsService::class)->download($invoice))
        ->toThrow(RuntimeException::class);

    Http::assertNothingSent();
});

// ==================== ARCHIVOS LOCALES FALTANTES ====================

test('si la DB dice stored pero el archivo ya no existe en Storage, lanza CfdiArtifactMissingException y marca reconciliation_required — nunca responde como válido en silencio', function () {
    $company = Company::factory()->create();
    $invoice = stampedInvoiceForArtifactsTest($company);
    app(CurrentTenant::class)->set($company->id);

    fakeArtifactsHttp($invoice);
    $first = app(DownloadInvoiceArtifactsService::class)->download($invoice);

    // Simula que el archivo desapareció del storage (ej. borrado manual,
    // migración de infraestructura) sin que la DB se enterara.
    Storage::disk('local')->delete($first->xmlPath);

    Http::fake(); // una recuperación válida no debería hacer HTTP tampoco

    expect(fn () => app(DownloadInvoiceArtifactsService::class)->download($invoice->fresh()))
        ->toThrow(CfdiArtifactMissingException::class);

    Http::assertNothingSent();

    $fresh = $invoice->fresh();
    expect($fresh->cfdi_artifacts_status)->toBe('reconciliation_required')
        // metadata histórica nunca se borra automáticamente:
        ->and($fresh->cfdi_xml_path)->toBe($first->xmlPath)
        ->and($fresh->cfdi_xml_sha256)->toBe($first->xmlHash);
});

// ==================== ERRORES PAC / COMPENSACIÓN ====================

test('si el PAC falla al descargar el XML, no deja ningún artifact parcial y marca el estado según el tipo de fallo', function () {
    $company = Company::factory()->create();
    $invoice = stampedInvoiceForArtifactsTest($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake([
        "*/invoices/{$invoice->pac_external_id}/xml" => Http::response(['message' => 'RFC inválido'], 400),
    ]);

    expect(fn () => app(DownloadInvoiceArtifactsService::class)->download($invoice))
        ->toThrow(PacValidationException::class);

    $fresh = $invoice->fresh();
    expect($fresh->cfdi_artifacts_status)->toBe('failed')
        ->and($fresh->cfdi_xml_path)->toBeNull();
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('un 5xx del PAC al descargar es AMBIGUO: cfdi_artifacts_status=reconciliation_required, nunca failed', function () {
    $company = Company::factory()->create();
    $invoice = stampedInvoiceForArtifactsTest($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake([
        "*/invoices/{$invoice->pac_external_id}/xml" => Http::response(['message' => 'Error interno del PAC'], 500),
    ]);

    expect(fn () => app(DownloadInvoiceArtifactsService::class)->download($invoice))
        ->toThrow(PacUnavailableException::class);

    expect($invoice->fresh()->cfdi_artifacts_status)->toBe('reconciliation_required')
        ->and($invoice->pacEvents()->pluck('event_type')->all())->toBe([
            InvoicePacEventType::ArtifactsFailed,
        ]);
});

test('cfdi_artifacts_last_error nunca contiene la API key, Authorization ni Bearer', function () {
    $company = Company::factory()->create();
    $invoice = stampedInvoiceForArtifactsTest($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake([
        "*/invoices/{$invoice->pac_external_id}/xml" => Http::response(['message' => 'RFC inválido', 'code' => 'invalid_rfc'], 400),
    ]);

    try {
        app(DownloadInvoiceArtifactsService::class)->download($invoice);
    } catch (PacValidationException) {
        // esperado
    }

    $error = $invoice->fresh()->cfdi_artifacts_last_error;

    expect($error)->toContain('invalid_rfc')
        ->and($error)->not->toContain('sk_test_ARTIFACTS_SERVICE')
        ->and($error)->not->toContain('Bearer')
        ->and($error)->not->toContain('Authorization');
});

test('el log de un intento exitoso nunca incluye el contenido del XML/PDF ni la API key', function () {
    Log::spy();

    $company = Company::factory()->create();
    $invoice = stampedInvoiceForArtifactsTest($company);
    app(CurrentTenant::class)->set($company->id);

    fakeArtifactsHttp($invoice);

    app(DownloadInvoiceArtifactsService::class)->download($invoice);

    Log::shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context) {
            if ($message !== 'billing.invoice.cfdi_artifacts_attempt') {
                return false;
            }

            expect($context)->not->toHaveKey('xml')
                ->and($context)->not->toHaveKey('pdf')
                ->and($context)->not->toHaveKey('cfdi_xml_path');

            $serialized = json_encode($context);
            expect($serialized)->not->toContain('sk_test_ARTIFACTS_SERVICE')
                ->and($serialized)->not->toContain('TimbreFiscalDigital')
                ->and($serialized)->not->toContain('%PDF-');

            return true;
        })
        ->atLeast()
        ->once();
});
