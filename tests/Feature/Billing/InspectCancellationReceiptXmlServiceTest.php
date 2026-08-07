<?php

use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\Billing\InspectCancellationReceiptXmlService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_XML_INSPECTOR_SECRET',
    ]);

    Http::preventStrayRequests();
    Storage::fake('local');
});

function invoiceForCancellationReceiptInspection(array $overrides = []): Invoice
{
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Issued,
        'client_rfc' => 'RFCSECRETOINSPECT01',
    ]);

    $invoice->forceFill(array_merge([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_inspect_'.$invoice->id,
        'cfdi_uuid' => '96013e83-154b-4153-8e61-c38b8966e560',
        'pac_status' => 'canceled',
        'cancellation_status' => 'accepted',
        'cancellation_receipt_status' => 'reconciliation_required',
        'cancellation_receipt_last_error' => 'error previo que debe quedar intacto',
    ], $overrides))->save();

    app(CurrentTenant::class)->set($company->id);

    return $invoice->fresh();
}

test('inspecciona estructura namespaces y candidatos UUID sin descargar PDF ni persistir', function () {
    $invoice = invoiceForCancellationReceiptInspection();
    $before = $invoice->getRawOriginal();
    $wrong = '11111111-2222-3333-4444-555555555555';
    $request = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    $xml = '<?xml version="1.0"?>'
        .'<sat:Acuse xmlns:sat="http://cancelacfd.sat.gob.mx" xmlns:ds="http://www.w3.org/2000/09/xmldsig#" RequestUUID="'.$request.'">'
        .'<sat:Folios><sat:Folio><sat:UUID>'.$wrong.'</sat:UUID></sat:Folio>'
        .'<sat:Folio FolioFiscal="'.strtoupper((string) $invoice->cfdi_uuid).'"><sat:EstatusUUID>201</sat:EstatusUUID></sat:Folio></sat:Folios>'
        .'<sat:RfcEmisor>RFC-QUE-NO-DEBE-APARECER</sat:RfcEmisor>'
        .'<ds:Signature><ds:X509Certificate>CERTIFICADO-QUE-NO-DEBE-APARECER</ds:X509Certificate></ds:Signature>'
        .'</sat:Acuse>';

    Http::fake([
        "*/invoices/{$invoice->pac_external_id}/cancellation_receipt/xml" => Http::response($xml, 200),
    ]);

    $result = app(InspectCancellationReceiptXmlService::class)->inspect($invoice);

    expect($result['root'])->toBe('Acuse')
        ->and($result['root_namespace'])->toBe('http://cancelacfd.sat.gob.mx')
        ->and($result['namespaces'])->toContain('http://cancelacfd.sat.gob.mx', 'http://www.w3.org/2000/09/xmldsig')
        ->and($result['elements'])->toContain(
            '/Acuse/Folios/Folio/UUID',
            '/Acuse/Signature/X509Certificate',
        )
        ->and($result['uuid_fields'])->toContain([
            'kind' => 'attribute',
            'location' => '/Acuse/@RequestUUID',
            'name' => 'RequestUUID',
        ])
        ->and($result['uuid_candidates'])->toHaveCount(3)
        ->and(collect($result['uuid_candidates'])->where('matches_invoice', true))->toHaveCount(1)
        ->and(collect($result['uuid_candidates'])->pluck('value')->all())->toContain(
            'aaaaaaaa...eeee',
            '11111111...5555',
            '96013E83...E560',
        );

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/cancellation_receipt/xml'));
    Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/cancellation_receipt/pdf'));
    expect($invoice->fresh()->getRawOriginal())->toBe($before)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(json_encode($result))->not->toContain('RFC-QUE-NO-DEBE-APARECER')
        ->not->toContain('CERTIFICADO-QUE-NO-DEBE-APARECER')
        ->not->toContain((string) $invoice->cfdi_uuid)
        ->not->toContain($xml);
});

test('XML sin identificadores conserva la estructura y reporta cero candidatos', function () {
    $invoice = invoiceForCancellationReceiptInspection();
    Http::fake([
        '*' => Http::response('<Acuse xmlns="urn:sat:acuse"><Resultado><Estado>201</Estado></Resultado></Acuse>', 200),
    ]);

    $result = app(InspectCancellationReceiptXmlService::class)->inspect($invoice);

    expect($result['root'])->toBe('Acuse')
        ->and($result['elements'])->toBe(['/Acuse', '/Acuse/Resultado', '/Acuse/Resultado/Estado'])
        ->and($result['uuid_fields'])->toBe([])
        ->and($result['uuid_candidates'])->toBe([]);
});

test('XML mal formado se rechaza sin persistencia', function () {
    $invoice = invoiceForCancellationReceiptInspection();
    $before = $invoice->getRawOriginal();
    Http::fake(['*' => Http::response('<Acuse><Folios>', 200)]);

    expect(fn () => app(InspectCancellationReceiptXmlService::class)->inspect($invoice))
        ->toThrow(RuntimeException::class, 'mal formado');

    expect($invoice->fresh()->getRawOriginal())->toBe($before)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('DOCTYPE y XXE se rechazan antes de resolver entidades externas', function () {
    $invoice = invoiceForCancellationReceiptInspection();
    $xml = '<?xml version="1.0"?><!DOCTYPE Acuse [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
        .'<Acuse><Folios><UUID>&xxe;</UUID></Folios></Acuse>';
    Http::fake(['*' => Http::response($xml, 200)]);

    expect(fn () => app(InspectCancellationReceiptXmlService::class)->inspect($invoice))
        ->toThrow(RuntimeException::class, 'DOCTYPE no permitido');

    Http::assertSentCount(1);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('tenant incorrecto falla antes de HTTP', function () {
    $invoice = invoiceForCancellationReceiptInspection();
    $otherCompany = Company::factory()->create();
    app(CurrentTenant::class)->set($otherCompany->id);
    Http::fake();

    expect(fn () => app(InspectCancellationReceiptXmlService::class)->inspect($invoice))
        ->toThrow(ModelNotFoundException::class);

    Http::assertNothingSent();
});
