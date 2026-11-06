<?php

use App\Enums\InvoicePacEventType;
use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Exceptions\Billing\CancellationReceiptStatusRejectedException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\DownloadCancellationReceiptService;
use App\Services\Billing\InvoiceBusinessCapabilitiesService;
use App\Services\Billing\InvoiceHealthEvaluator;
use App\Support\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Fase 6.31 (7): el acuse se acepta sólo si Acuse/Folios/UUID corresponde
 * al CFDI y el EstatusUUID de ese mismo Folios es 201 o 202. Se sigue
 * exigiendo pac_status=canceled y cancellation_status=accepted.
 */
const PHASE_631_RS_UUID = '96013e83-154b-4153-8e61-c38b8966e560';

beforeEach(function () {
    config(['services.facturapi.base_url' => 'https://example-pac.test/v2', 'billing.pac_mode' => 'test']);
    Http::preventStrayRequests();
    Storage::fake('local');
});

/** @return array{Company, User, Invoice} */
function phase631ReceiptInvoice(): array
{
    $company = Company::factory()->withFacturapiOrganization('sk_test_PHASE_631_RS_TEST_KEY_1')->create();
    $user = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Admin]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Cancelled, 'total' => 116]);
    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_631_rs_'.$invoice->id,
        'cfdi_uuid' => PHASE_631_RS_UUID,
        'pac_status' => 'canceled',
        'pac_issue_status' => 'succeeded',
        'cancellation_status' => 'accepted',
        'pac_cancel_request_status' => 'completed',
        'stamped_at' => now(),
    ])->save();
    app(CurrentTenant::class)->set($company->id);

    return [$company, $user, $invoice->fresh()];
}

function phase631ReceiptFake(Invoice $invoice, string $folios): void
{
    Http::fake([
        "*/invoices/{$invoice->pac_external_id}/cancellation_receipt/xml" => Http::response('<?xml version="1.0"?><Acuse xmlns="http://cancelacfd.sat.gob.mx">'.$folios.'</Acuse>'),
        "*/invoices/{$invoice->pac_external_id}/cancellation_receipt/pdf" => Http::response("%PDF-1.7\nacuse\n%%EOF"),
    ]);
}

test('un acuse del CFDI con EstatusUUID 201 o 202 se guarda', function (string $status) {
    [, , $invoice] = phase631ReceiptInvoice();
    phase631ReceiptFake($invoice, '<Folios><UUID>'.strtoupper(PHASE_631_RS_UUID).'</UUID><EstatusUUID>'.$status.'</EstatusUUID></Folios>');

    app(DownloadCancellationReceiptService::class)->download($invoice);

    expect($invoice->fresh()->cancellation_receipt_status)->toBe('stored');
})->with(['201', '202']);

test('un acuse del CFDI con otro EstatusUUID o sin él se bloquea sin guardar nada', function (string $statusNode, string $expected) {
    [, , $invoice] = phase631ReceiptInvoice();
    phase631ReceiptFake($invoice, '<Folios><UUID>'.PHASE_631_RS_UUID.'</UUID>'.$statusNode.'</Folios>');

    expect(fn () => app(DownloadCancellationReceiptService::class)->download($invoice))
        ->toThrow(CancellationReceiptStatusRejectedException::class, "EstatusUUID es {$expected}");

    $fresh = $invoice->fresh();
    $event = $fresh->pacEvents()->where('event_type', InvoicePacEventType::CancellationReceiptStatusRejected->value)->firstOrFail();

    expect($fresh->cancellation_receipt_status)->toBe('reconciliation_required')
        ->and($fresh->cancellation_receipt_xml_path)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and($event->context['receipt_status'])->toBe($expected)
        ->and(json_encode($event->context))->not->toContain(PHASE_631_RS_UUID);
})->with([
    'UUID no existe (205)' => ['<EstatusUUID>205</EstatusUUID>', '205'],
    'no corresponde al emisor (203)' => ['<EstatusUUID>203</EstatusUUID>', '203'],
    'ausente' => ['', '(ausente)'],
    'no numérico' => ['<EstatusUUID>cancelado</EstatusUUID>', '[no numérico]'],
]);

test('el estatus que cuenta es el del mismo Folios que trae el UUID del CFDI', function () {
    [, , $invoice] = phase631ReceiptInvoice();
    phase631ReceiptFake($invoice,
        '<Folios><UUID>11111111-1111-1111-1111-111111111111</UUID><EstatusUUID>201</EstatusUUID></Folios>'
        .'<Folios><UUID>'.PHASE_631_RS_UUID.'</UUID><EstatusUUID>205</EstatusUUID></Folios>');

    expect(fn () => app(DownloadCancellationReceiptService::class)->download($invoice))
        ->toThrow(CancellationReceiptStatusRejectedException::class, '205');
});

test('la API responde CANCELLATION_RECEIPT_STATUS_REJECTED y el bloqueo cuenta para el límite', function () {
    [$company, $user, $invoice] = phase631ReceiptInvoice();
    phase631ReceiptFake($invoice, '<Folios><UUID>'.PHASE_631_RS_UUID.'</UUID><EstatusUUID>205</EstatusUUID></Folios>');
    app(CurrentTenant::class)->clear();

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/pac/cancellation-receipt")
        ->assertStatus(409)
        ->assertJsonPath('code', 'CANCELLATION_RECEIPT_STATUS_REJECTED');

    app(CurrentTenant::class)->set($company->id);
    $fresh = $invoice->fresh();

    expect(app(InvoiceBusinessCapabilitiesService::class)->cancellationReceiptFetchable($fresh))->toBeFalse()
        ->and(app(InvoiceHealthEvaluator::class)->evaluate($fresh, CarbonImmutable::now())->find('cancellation_receipt_status_rejected')?->status->value)->toBe('recoverable');
});

test('sin pac_status=canceled y cancellation_status=accepted no se pide el acuse aunque el estatus fuera 201', function () {
    [, , $invoice] = phase631ReceiptInvoice();
    DB::table('invoices')->where('id', $invoice->id)->update(['cancellation_status' => 'pending', 'pac_status' => 'valid']);
    phase631ReceiptFake($invoice, '<Folios><UUID>'.PHASE_631_RS_UUID.'</UUID><EstatusUUID>201</EstatusUUID></Folios>');

    expect(fn () => app(DownloadCancellationReceiptService::class)->download($invoice->fresh()))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

test('billing:cancellation-receipt-inspect muestra EstatusUUID sin guardar nada, también en Live', function () {
    [$company, , $invoice] = phase631ReceiptInvoice();
    phase631MakeLive($company->id, 'sk_live_PHASE_631_RS_LIVE_KEY_01', $invoice);
    phase631ReceiptFake($invoice, '<Folios><UUID>'.PHASE_631_RS_UUID.'</UUID><EstatusUUID>201</EstatusUUID></Folios>');
    app(CurrentTenant::class)->clear();

    $this->artisan('billing:cancellation-receipt-inspect', ['invoice' => $invoice->id])
        ->expectsConfirmation("¿Confirmas la inspección de solo lectura de la factura [{$invoice->id}]?", 'yes')
        ->expectsOutputToContain('96013e83...e560')
        ->expectsOutputToContain('201')
        ->doesntExpectOutputToContain(PHASE_631_RS_UUID)
        ->assertExitCode(0);

    expect(DB::table('invoices')->where('id', $invoice->id)->value('cancellation_receipt_status'))->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(DB::table('invoice_pac_events')->where('invoice_id', $invoice->id)->count())->toBe(0);
    Http::assertSent(fn ($request): bool => $request->header('Authorization')[0] === 'Bearer sk_live_PHASE_631_RS_LIVE_KEY_01'
        && str_ends_with($request->url(), '/cancellation_receipt/xml'));
    Http::assertSentCount(1);
});

test('billing:cancellation-receipt-inspect no hace HTTP sin confirmación', function () {
    [, , $invoice] = phase631ReceiptInvoice();
    app(CurrentTenant::class)->clear();

    $this->artisan('billing:cancellation-receipt-inspect', ['invoice' => $invoice->id])
        ->expectsConfirmation("¿Confirmas la inspección de solo lectura de la factura [{$invoice->id}]?", 'no')
        ->assertExitCode(0);

    Http::assertNothingSent();
});
