<?php

use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\Billing\AuditCancellationIdentityService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_IDENTITY_AUDIT_SECRET',
    ]);

    Http::preventStrayRequests();
    Storage::fake('local');
});

function invoiceForCancellationIdentityAudit(string $localUuid, array $overrides = []): Invoice
{
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Issued,
        'client_rfc' => 'RFCSECRETOIDENTITY01',
    ]);

    $cfdiXml = '<?xml version="1.0"?>'
        .'<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" Rfc="RFC-ORIGINAL-SECRETO">'
        .'<cfdi:Complemento><tfd:TimbreFiscalDigital xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" UUID="'.$localUuid.'" SelloCFD="SELLO-SECRETO"/></cfdi:Complemento>'
        .'</cfdi:Comprobante>';
    $path = 'cfdi-audit/'.$company->id.'/'.$invoice->id.'.xml';
    Storage::disk('local')->put($path, $cfdiXml);

    $invoice->forceFill(array_merge([
        'pac_provider' => 'facturapi',
        'pac_external_id' => '6a7645c39bb354793ba2ab2f',
        'cfdi_uuid' => $localUuid,
        'pac_status' => 'canceled',
        'cancellation_status' => 'accepted',
        'pac_response' => ['status' => 'canceled', 'uuid' => $localUuid, 'secret' => 'PAC-RAW-SECRETO'],
        'pac_draft_response' => ['status' => 'valid', 'uuid' => $localUuid, 'secret' => 'DRAFT-RAW-SECRETO'],
        'cfdi_xml_path' => $path,
        'cfdi_xml_sha256' => hash('sha256', $cfdiXml),
        'cfdi_artifacts_status' => 'stored',
        'cancellation_receipt_status' => 'reconciliation_required',
        'cancellation_receipt_last_error' => 'mismatch previo intacto',
    ], $overrides))->save();

    app(CurrentTenant::class)->set($company->id);

    return $invoice->fresh();
}

function fakeCancellationIdentityAuditHttp(Invoice $invoice, string $remoteUuid, string $receiptUuid): void
{
    Http::fake(function ($request) use ($invoice, $remoteUuid, $receiptUuid) {
        if (str_ends_with($request->url(), "/invoices/{$invoice->pac_external_id}/cancellation_receipt/xml")) {
            return Http::response(
                '<Acuse xmlns="http://cancelacfd.sat.gob.mx"><Folios><UUID>'.$receiptUuid.'</UUID></Folios><RfcEmisor>RFC-ACUSE-SECRETO</RfcEmisor></Acuse>',
                200,
            );
        }

        if (str_ends_with($request->url(), "/invoices/{$invoice->pac_external_id}")) {
            return Http::response([
                'id' => $invoice->pac_external_id,
                'status' => 'canceled',
                'cancellation_status' => 'accepted',
                'uuid' => $remoteUuid,
                'livemode' => false,
                'stamp' => ['date' => '2026-08-01T12:34:56.000Z'],
                'customer' => ['tax_id' => 'RFC-REMOTO-SECRETO'],
            ], 200);
        }

        return Http::response(['code' => 'unexpected_test_request'], 500);
    });
}

test('escenario 1 correlaciona A B C D y conserva DB y artifacts byte por byte', function () {
    $local = '96013e83-154b-4153-8e61-c38b8966e560';
    $receipt = 'cf5138a2-1111-2222-3333-444444442e90';
    $invoice = invoiceForCancellationIdentityAudit($local);
    $before = $invoice->getRawOriginal();
    $storedXml = Storage::disk('local')->get((string) $invoice->cfdi_xml_path);
    fakeCancellationIdentityAuditHttp($invoice, strtoupper($local), strtoupper($receipt));

    $audit = app(AuditCancellationIdentityService::class)->audit($invoice);

    expect($audit['local']['uuid'])->toBe('96013e83...e560')
        ->and($audit['remote']['uuid'])->toBe('96013E83...E560')
        ->and($audit['remote']['id_matches_local'])->toBeTrue()
        ->and($audit['remote']['status'])->toBe('canceled')
        ->and($audit['remote']['cancellation_status'])->toBe('accepted')
        ->and($audit['remote']['livemode'])->toBeFalse()
        ->and($audit['remote']['stamp_date'])->toContain('2026-08-01T12:34:56')
        ->and($audit['receipt']['uuids'])->toBe(['CF5138A2...2E90'])
        ->and($audit['cfdi_xml']['state'])->toBe('verified')
        ->and($audit['cfdi_xml']['hash_matches_metadata'])->toBeTrue()
        ->and($audit['cfdi_xml']['uuid'])->toBe('96013e83...e560')
        ->and($audit['comparisons'])->toMatchArray([
            'local_equals_remote' => true,
            'local_equals_receipt' => false,
            'remote_equals_receipt' => false,
            'local_equals_cfdi_xml' => true,
            'remote_equals_cfdi_xml' => true,
            'receipt_equals_cfdi_xml' => false,
        ])
        ->and($audit['scenario'])->toBe('scenario_1_receipt_does_not_belong_to_expected_cfdi');

    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), "/invoices/{$invoice->pac_external_id}"));
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/cancellation_receipt/xml'));
    Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/cancellation_receipt/pdf'));
    expect($invoice->fresh()->getRawOriginal())->toBe($before)
        ->and(Storage::disk('local')->get((string) $invoice->cfdi_xml_path))->toBe($storedXml)
        ->and(json_encode($audit))->not->toContain($local)
        ->not->toContain($receipt)
        ->not->toContain('RFC-REMOTO-SECRETO')
        ->not->toContain('RFC-ACUSE-SECRETO')
        ->not->toContain('PAC-RAW-SECRETO')
        ->not->toContain('DRAFT-RAW-SECRETO')
        ->not->toContain('SELLO-SECRETO');
});

test('escenario 3 detecta que remoto y acuse coinciden pero difieren de DB y XML original', function () {
    $local = '96013e83-154b-4153-8e61-c38b8966e560';
    $remote = 'cf5138a2-1111-2222-3333-444444442e90';
    $invoice = invoiceForCancellationIdentityAudit($local);
    fakeCancellationIdentityAuditHttp($invoice, $remote, strtoupper($remote));

    $audit = app(AuditCancellationIdentityService::class)->audit($invoice);

    expect($audit['comparisons']['local_equals_remote'])->toBeFalse()
        ->and($audit['comparisons']['remote_equals_receipt'])->toBeTrue()
        ->and($audit['comparisons']['local_equals_cfdi_xml'])->toBeTrue()
        ->and($audit['scenario'])->toBe('scenario_3_remote_identity_differs_from_original');
});

test('escenario 4 detecta las cuatro identidades iguales sin depender del casing', function () {
    $local = '96013e83-154b-4153-8e61-c38b8966e560';
    $invoice = invoiceForCancellationIdentityAudit($local);
    fakeCancellationIdentityAuditHttp($invoice, strtoupper($local), strtoupper($local));

    $audit = app(AuditCancellationIdentityService::class)->audit($invoice);

    expect($audit['scenario'])->toBe('scenario_4_all_equal')
        ->and(collect($audit['comparisons'])->every(fn ($value): bool => $value === true))->toBeTrue();
});

test('hash distinto vuelve no confiable al UUID del XML original y no inventa comparacion', function () {
    $local = '96013e83-154b-4153-8e61-c38b8966e560';
    $invoice = invoiceForCancellationIdentityAudit($local, ['cfdi_xml_sha256' => str_repeat('a', 64)]);
    fakeCancellationIdentityAuditHttp($invoice, $local, $local);

    $audit = app(AuditCancellationIdentityService::class)->audit($invoice);

    expect($audit['cfdi_xml']['state'])->toBe('hash_mismatch')
        ->and($audit['cfdi_xml']['uuid'])->toBeNull()
        ->and($audit['comparisons']['local_equals_cfdi_xml'])->toBeNull()
        ->and($audit['scenario'])->toBe('undetermined');
});

test('DOCTYPE en acuse se rechaza sin resolver XXE ni modificar estado', function () {
    $local = '96013e83-154b-4153-8e61-c38b8966e560';
    $invoice = invoiceForCancellationIdentityAudit($local);
    $before = $invoice->getRawOriginal();

    Http::fake(function ($request) use ($invoice, $local) {
        if (str_ends_with($request->url(), '/cancellation_receipt/xml')) {
            return Http::response(
                '<!DOCTYPE Acuse [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><Acuse><Folios><UUID>&xxe;</UUID></Folios></Acuse>',
                200,
            );
        }

        return Http::response([
            'id' => $invoice->pac_external_id,
            'status' => 'canceled',
            'cancellation_status' => 'accepted',
            'uuid' => $local,
            'livemode' => false,
        ], 200);
    });

    expect(fn () => app(AuditCancellationIdentityService::class)->audit($invoice))
        ->toThrow(RuntimeException::class, 'DOCTYPE no permitido');

    expect($invoice->fresh()->getRawOriginal())->toBe($before);
});

test('pac_response actual se audita como snapshot y no se confunde con historial de stamp', function () {
    $local = '96013e83-154b-4153-8e61-c38b8966e560';
    $invoice = invoiceForCancellationIdentityAudit($local, [
        'pac_response' => ['status' => 'canceled'],
        'pac_draft_response' => ['status' => 'valid', 'uuid' => strtoupper($local)],
    ]);
    fakeCancellationIdentityAuditHttp($invoice, $local, $local);

    $audit = app(AuditCancellationIdentityService::class)->audit($invoice);

    expect($audit['history']['pac_response'])->toMatchArray([
        'present' => true,
        'status' => 'canceled',
        'uuid_state' => 'absent',
        'uuid' => null,
        'uuid_matches_local' => null,
    ])->and($audit['history']['pac_draft_response']['uuid_matches_local'])->toBeTrue()
        ->and($audit['history']['note'])->toContain('no es un historial inmutable');
});
