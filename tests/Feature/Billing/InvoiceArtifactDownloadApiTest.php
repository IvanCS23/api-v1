<?php

use App\Contracts\Billing\PacProvider;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['services.facturapi.test_key' => 'sk_test_ARTIFACT_API_SECRET']);
    Http::preventStrayRequests();
    Http::fake();
    Storage::fake('local');
    Storage::fake('public');

    $this->app->bind(PacProvider::class, function (): never {
        throw new RuntimeException('PacProvider no debe resolverse desde endpoints de artifacts locales.');
    });
});

function phase69CfdiXml(): string
{
    return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<cfdi:Comprobante xmlns:cfdi=\"http://www.sat.gob.mx/cfd/4\"/>";
}

function phase69CfdiPdf(): string
{
    return "%PDF-1.7\nCFDI privado exacto\n%%EOF";
}

function phase69ReceiptXml(): string
{
    return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<Acuse xmlns=\"http://cancelacfd.sat.gob.mx\"/>";
}

function phase69ReceiptPdf(): string
{
    return "%PDF-1.7\nAcuse privado exacto\n%%EOF";
}

/** @return array{0: Company, 1: User, 2: Invoice} */
function phase69ArtifactFixture(array $overrides = [], bool $writeFiles = true): array
{
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Issued,
    ]);
    $uuid = '96013e83-154b-4153-8e61-c38b8966e560';
    $cfdiBase = "cfdi/{$company->id}/{$invoice->id}/{$uuid}";
    $receiptBase = "cancellation-receipts/{$company->id}/{$invoice->id}/{$uuid}";
    $xml = phase69CfdiXml();
    $pdf = phase69CfdiPdf();
    $receiptXml = phase69ReceiptXml();
    $receiptPdf = phase69ReceiptPdf();

    $invoice->forceFill(array_merge([
        'folio' => 'FAC-00000002',
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_private_phase_69_'.$invoice->id,
        'cfdi_uuid' => $uuid,
        'pac_status' => 'canceled',
        'cancellation_status' => 'accepted',
        'pac_issue_status' => 'succeeded',
        'cfdi_artifacts_status' => 'stored',
        'cfdi_xml_path' => "{$cfdiBase}.xml",
        'cfdi_pdf_path' => "{$cfdiBase}.pdf",
        'cfdi_xml_sha256' => hash('sha256', $xml),
        'cfdi_pdf_sha256' => hash('sha256', $pdf),
        'cfdi_xml_size' => strlen($xml),
        'cfdi_pdf_size' => strlen($pdf),
        'cancellation_receipt_status' => 'stored',
        'cancellation_receipt_xml_path' => "{$receiptBase}.xml",
        'cancellation_receipt_pdf_path' => "{$receiptBase}.pdf",
        'cancellation_receipt_xml_sha256' => hash('sha256', $receiptXml),
        'cancellation_receipt_pdf_sha256' => hash('sha256', $receiptPdf),
        'cancellation_receipt_xml_size' => strlen($receiptXml),
        'cancellation_receipt_pdf_size' => strlen($receiptPdf),
    ], $overrides))->save();

    if ($writeFiles) {
        $fresh = $invoice->fresh();
        $files = [
            $fresh->cfdi_xml_path => $xml,
            $fresh->cfdi_pdf_path => $pdf,
            $fresh->cancellation_receipt_xml_path => $receiptXml,
            $fresh->cancellation_receipt_pdf_path => $receiptPdf,
        ];

        foreach ($files as $path => $contents) {
            if (is_string($path) && $path !== '' && ! str_contains($path, "\0")) {
                Storage::disk('local')->put($path, $contents);
            }
        }
    }

    app(CurrentTenant::class)->set($company->id);

    return [$company, $user, $invoice->fresh()];
}

test('CFDI XML y PDF se entregan byte por byte con headers privados y filename seguro', function () {
    [, $user, $invoice] = phase69ArtifactFixture();
    app(CurrentTenant::class)->clear();

    $xml = $this->actingAs($user, 'api')
        ->get("/api/invoices/{$invoice->id}/cfdi/xml")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml')
        ->assertHeader('Content-Disposition', 'attachment; filename=CFDI-FAC-00000002.xml')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Pragma', 'no-cache');

    $pdf = $this->actingAs($user, 'api')
        ->get("/api/invoices/{$invoice->id}/cfdi/pdf")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'attachment; filename=CFDI-FAC-00000002.pdf');

    expect($xml->streamedContent())->toBe(phase69CfdiXml())
        ->and($pdf->streamedContent())->toBe(phase69CfdiPdf())
        ->and((string) $xml->headers->get('Cache-Control'))
        ->toContain('private', 'no-store', 'max-age=0');

    Http::assertNothingSent();
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

test('requiere autenticación y falla cerrado para otro tenant o Invoice inexistente', function () {
    [, $owner, $invoice] = phase69ArtifactFixture();
    $foreignCompany = Company::factory()->create();
    $foreignUser = User::factory()->create(['company_id' => $foreignCompany->id]);
    app(CurrentTenant::class)->clear();

    $this->getJson("/api/invoices/{$invoice->id}/cfdi/xml")->assertUnauthorized();
    $this->actingAs($foreignUser, 'api')
        ->getJson("/api/invoices/{$invoice->id}/cfdi/xml")
        ->assertNotFound();
    $this->actingAs($owner, 'api')
        ->getJson('/api/invoices/999999999/cfdi/xml')
        ->assertNotFound();

    Http::assertNothingSent();
});

test('aplica InvoicePolicy antes de leer Storage', function () {
    [, $user, $invoice] = phase69ArtifactFixture();
    app(CurrentTenant::class)->clear();
    Gate::before(fn (): bool => false);

    $this->actingAs($user, 'api')
        ->getJson("/api/invoices/{$invoice->id}/cfdi/pdf")
        ->assertForbidden();

    Http::assertNothingSent();
});

test('artifact nunca almacenado responde 404 y no intenta leer ni filtra metadata', function (string $endpoint, array $overrides) {
    [, $user, $invoice] = phase69ArtifactFixture($overrides, false);
    app(CurrentTenant::class)->clear();

    $response = $this->actingAs($user, 'api')
        ->getJson("/api/invoices/{$invoice->id}/{$endpoint}")
        ->assertNotFound()
        ->assertJsonStructure(['message']);

    expect($response->getContent())->not->toContain(
        'storage/app', 'private/', 'cfdi/', 'cancellation-receipts/',
        'sha256', 'sk_test_ARTIFACT_API_SECRET', 'Authorization',
    );
    Http::assertNothingSent();
})->with([
    'cfdi XML' => ['cfdi/xml', ['cfdi_artifacts_status' => null]],
    'cfdi PDF' => ['cfdi/pdf', ['cfdi_artifacts_status' => 'failed']],
    'acuse mismatch XML' => ['cancellation-receipt/xml', [
        'cancellation_receipt_status' => 'reconciliation_required',
        'cancellation_receipt_last_error' => '[CANCELLATION_RECEIPT_UUID_MISMATCH] privado',
    ]],
    'acuse mismatch PDF' => ['cancellation-receipt/pdf', [
        'cancellation_receipt_status' => 'reconciliation_required',
        'cancellation_receipt_last_error' => '[CANCELLATION_RECEIPT_UUID_MISMATCH] privado',
    ]],
]);

test('metadata stored incompleta responde 409 sin intentar reparar ni exponer detalle', function (string $endpoint, array $overrides) {
    [, $user, $invoice] = phase69ArtifactFixture($overrides, false);
    $before = $invoice->getRawOriginal();
    app(CurrentTenant::class)->clear();

    $response = $this->actingAs($user, 'api')
        ->getJson("/api/invoices/{$invoice->id}/{$endpoint}")
        ->assertConflict()
        ->assertExactJson(['message' => 'El artifact fiscal no supera la validación de integridad.']);

    expect($invoice->fresh()->getRawOriginal())->toBe($before)
        ->and($response->getContent())->not->toContain('cfdi_', 'cancellation_receipt_', 'sha256');
    Http::assertNothingSent();
})->with([
    'XML sin path' => ['cfdi/xml', ['cfdi_xml_path' => null]],
    'XML sin hash' => ['cfdi/xml', ['cfdi_xml_sha256' => null]],
    'PDF sin size' => ['cfdi/pdf', ['cfdi_pdf_size' => null]],
    'acuse sin path' => ['cancellation-receipt/xml', ['cancellation_receipt_xml_path' => null]],
]);

test('archivo físico faltante responde 409 sin mutar Invoice', function (string $endpoint) {
    [, $user, $invoice] = phase69ArtifactFixture(writeFiles: false);
    $before = $invoice->getRawOriginal();
    app(CurrentTenant::class)->clear();

    $this->actingAs($user, 'api')
        ->getJson("/api/invoices/{$invoice->id}/{$endpoint}")
        ->assertConflict();

    expect($invoice->fresh()->getRawOriginal())->toBe($before);
    Http::assertNothingSent();
})->with(['cfdi/xml', 'cfdi/pdf', 'cancellation-receipt/xml', 'cancellation-receipt/pdf']);

test('hash o tamaño distinto responde 409 y jamás entrega bytes', function (string $endpoint, array $overrides, string $secretBytes) {
    [, $user, $invoice] = phase69ArtifactFixture($overrides);
    app(CurrentTenant::class)->clear();

    $response = $this->actingAs($user, 'api')
        ->getJson("/api/invoices/{$invoice->id}/{$endpoint}")
        ->assertConflict();

    expect($response->getContent())->not->toContain($secretBytes, 'sha256', 'storage/app');
    Http::assertNothingSent();
})->with([
    'XML hash' => ['cfdi/xml', ['cfdi_xml_sha256' => str_repeat('0', 64)], 'Comprobante'],
    'PDF hash' => ['cfdi/pdf', ['cfdi_pdf_sha256' => str_repeat('0', 64)], '%PDF-'],
    'XML size' => ['cfdi/xml', ['cfdi_xml_size' => 999999], 'Comprobante'],
    'PDF size' => ['cfdi/pdf', ['cfdi_pdf_size' => 999999], '%PDF-'],
    'acuse XML hash' => ['cancellation-receipt/xml', ['cancellation_receipt_xml_sha256' => str_repeat('0', 64)], '<Acuse'],
    'acuse PDF size' => ['cancellation-receipt/pdf', ['cancellation_receipt_pdf_size' => 999999], '%PDF-'],
]);

test('path corrupto traversal absoluto backslash u otro tenant responde 409 antes de leer', function (string $endpoint, string $pathField, string $path) {
    [$company, $user, $invoice] = phase69ArtifactFixture(writeFiles: false);
    $path = str_replace(['{company}', '{invoice}'], [(string) $company->id, (string) $invoice->id], $path);
    $invoice->forceFill([$pathField => $path])->save();
    app(CurrentTenant::class)->clear();

    $response = $this->actingAs($user, 'api')
        ->getJson("/api/invoices/{$invoice->id}/{$endpoint}")
        ->assertConflict();

    expect($response->getContent())->not->toContain($path, 'storage/app', 'private/');
    Http::assertNothingSent();
})->with([
    'XML traversal' => ['cfdi/xml', 'cfdi_xml_path', 'cfdi/{company}/{invoice}/../secret.xml'],
    'XML otro tenant' => ['cfdi/xml', 'cfdi_xml_path', 'cfdi/999999/{invoice}/96013e83-154b-4153-8e61-c38b8966e560.xml'],
    'XML otra invoice' => ['cfdi/xml', 'cfdi_xml_path', 'cfdi/{company}/999999/96013e83-154b-4153-8e61-c38b8966e560.xml'],
    'XML absoluto unix' => ['cfdi/xml', 'cfdi_xml_path', '/cfdi/{company}/{invoice}/96013e83-154b-4153-8e61-c38b8966e560.xml'],
    'XML drive letter' => ['cfdi/xml', 'cfdi_xml_path', 'C:/private/cfdi.xml'],
    'XML backslash' => ['cfdi/xml', 'cfdi_xml_path', 'cfdi\\{company}\\{invoice}\\96013e83-154b-4153-8e61-c38b8966e560.xml'],
    'XML null byte' => ['cfdi/xml', 'cfdi_xml_path', "cfdi/{company}/{invoice}/evil\0.xml"],
    'PDF traversal' => ['cfdi/pdf', 'cfdi_pdf_path', 'cfdi/{company}/{invoice}/../secret.pdf'],
    'PDF otro tenant' => ['cfdi/pdf', 'cfdi_pdf_path', 'cfdi/999999/{invoice}/96013e83-154b-4153-8e61-c38b8966e560.pdf'],
    'PDF otra invoice' => ['cfdi/pdf', 'cfdi_pdf_path', 'cfdi/{company}/999999/96013e83-154b-4153-8e61-c38b8966e560.pdf'],
]);

test('formato XML o PDF inválido falla aunque tamaño y hash coincidan', function (string $endpoint, string $pathField, string $hashField, string $sizeField, string $invalid) {
    [, $user, $invoice] = phase69ArtifactFixture();
    $path = $invoice->getAttribute($pathField);
    Storage::disk('local')->put($path, $invalid);
    $invoice->forceFill([
        $hashField => hash('sha256', $invalid),
        $sizeField => strlen($invalid),
    ])->save();
    app(CurrentTenant::class)->clear();

    $response = $this->actingAs($user, 'api')
        ->getJson("/api/invoices/{$invoice->id}/{$endpoint}")
        ->assertConflict();

    expect($response->getContent())->not->toContain($invalid);
    Http::assertNothingSent();
})->with([
    'XML' => ['cfdi/xml', 'cfdi_xml_path', 'cfdi_xml_sha256', 'cfdi_xml_size', 'not XML secret'],
    'PDF' => ['cfdi/pdf', 'cfdi_pdf_path', 'cfdi_pdf_sha256', 'cfdi_pdf_size', 'not PDF secret'],
]);

test('acuse almacenado se entrega y el mismatch actual nunca se entrega', function () {
    [, $user, $invoice] = phase69ArtifactFixture();
    app(CurrentTenant::class)->clear();

    $xml = $this->actingAs($user, 'api')
        ->get("/api/invoices/{$invoice->id}/cancellation-receipt/xml")
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename=ACUSE-FAC-00000002.xml');
    $pdf = $this->actingAs($user, 'api')
        ->get("/api/invoices/{$invoice->id}/cancellation-receipt/pdf")
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename=ACUSE-FAC-00000002.pdf');

    expect($xml->streamedContent())->toBe(phase69ReceiptXml())
        ->and($pdf->streamedContent())->toBe(phase69ReceiptPdf());

    Http::assertNothingSent();
});

test('filename sanitiza folio y query path malicioso es ignorado', function () {
    [, $user, $invoice] = phase69ArtifactFixture(['folio' => "FAC 2\r\nX-Evil: yes/../../"]);
    app(CurrentTenant::class)->clear();

    $response = $this->actingAs($user, 'api')
        ->get("/api/invoices/{$invoice->id}/cfdi/xml?path=../../.env")
        ->assertOk();

    $disposition = (string) $response->headers->get('Content-Disposition');
    expect($response->streamedContent())->toBe(phase69CfdiXml())
        ->and($disposition)->toStartWith('attachment; filename=CFDI-')
        ->and($disposition)->not->toContain("\r", "\n", '/', '\\', ':')
        ->and($disposition)->toEndWith('.xml');

    Http::assertNothingSent();
});

test('descarga local no modifica Invoice ni agrega eventos PAC', function () {
    [, $user, $invoice] = phase69ArtifactFixture();
    $before = $invoice->getRawOriginal();
    $eventCount = $invoice->pacEvents()->count();
    app(CurrentTenant::class)->clear();

    $this->actingAs($user, 'api')
        ->get("/api/invoices/{$invoice->id}/cfdi/xml")
        ->assertOk()
        ->streamedContent();

    app(CurrentTenant::class)->set($invoice->company_id);
    expect($invoice->fresh()->getRawOriginal())->toBe($before)
        ->and($invoice->pacEvents()->count())->toBe($eventCount)
        ->and(Storage::disk('public')->allFiles())->toBe([]);

    Http::assertNothingSent();
});
