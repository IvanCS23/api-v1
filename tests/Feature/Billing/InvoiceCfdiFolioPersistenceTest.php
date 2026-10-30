<?php

use App\Enums\InvoiceHealthAction;
use App\Enums\InvoiceHealthStatus;
use App\Enums\InvoicePacEventType;
use App\Enums\InvoiceStatus;
use App\Http\Resources\Billing\InvoicePacEventResource;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoicePacEvent;
use App\Services\Billing\InvoiceHealthEvaluator;
use App\Services\Billing\StampPacDraftInvoiceService;
use App\Support\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.28: la serie y el folio con que quedó timbrado el CFDI se guardan
 * desde la respuesta del PAC (cfdi_series/cfdi_folio_number). Si no son
 * los del ERP, queda en el historial y en billing:health; el timbrado no
 * se revierte.
 */
const PHASE628_UUID = 'bbbbbbbb-2222-4333-8444-555555555555';

beforeEach(function () {
    facturapiOrganizationsFromConfigKey();
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_PHASE_628_FOLIO',
    ]);
    Http::preventStrayRequests();
});

function phase628DraftReady(string $folio = 'FAC-00000019'): Invoice
{
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'folio' => $folio, 'status' => InvoiceStatus::Issued]);
    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_draft_external_id' => 'inv_folio_'.$invoice->id,
        'pac_draft_idempotency_key' => "erp-invoice-draft:{$company->id}:{$invoice->id}:v1",
        'pac_draft_status' => 'draft',
        'pac_draft_ready_to_stamp' => true,
    ])->save();
    app(CurrentTenant::class)->set($company->id);

    return $invoice->fresh();
}

/** Simula la sincronización del borrador y un /stamp con la serie y el folio dados. */
function phase628FakeStamp(string $remoteId, array $folio): void
{
    Http::fake([
        "*/invoices/{$remoteId}/stamp" => Http::response(array_merge([
            'id' => $remoteId, 'status' => 'valid', 'livemode' => false,
            'uuid' => PHASE628_UUID, 'stamp' => ['date' => '2026-10-30T10:00:00'],
        ], $folio)),
        "*/invoices/{$remoteId}" => Http::response([
            'id' => $remoteId, 'status' => 'draft', 'livemode' => false, 'is_ready_to_stamp' => true,
        ]),
    ]);
}

function phase628MismatchEvents(Invoice $invoice)
{
    return InvoicePacEvent::withoutGlobalScopes()
        ->where('invoice_id', $invoice->id)
        ->where('event_type', InvoicePacEventType::CfdiFolioMismatch->value)
        ->get();
}

function phase628Health(Invoice $invoice)
{
    return app(InvoiceHealthEvaluator::class)->evaluate($invoice, CarbonImmutable::now());
}

test('la migración agrega cfdi_series y cfdi_folio_number nullable', function () {
    expect(Schema::hasColumns('invoices', ['cfdi_series', 'cfdi_folio_number']))->toBeTrue();

    $invoice = phase628DraftReady();
    expect($invoice->cfdi_series)->toBeNull()
        ->and($invoice->cfdi_folio_number)->toBeNull();
});

test('al timbrar se guardan la serie y el folio del CFDI y, si coinciden, no se registra nada', function () {
    $invoice = phase628DraftReady('FAC-00000019');
    phase628FakeStamp($invoice->pac_draft_external_id, ['series' => 'FAC', 'folio_number' => 19]);

    $stamped = app(StampPacDraftInvoiceService::class)->stamp($invoice);

    expect($stamped->pac_issue_status)->toBe('succeeded')
        ->and($stamped->fresh()->cfdi_series)->toBe('FAC')
        ->and($stamped->fresh()->cfdi_folio_number)->toBe(19)
        ->and(phase628MismatchEvents($invoice))->toHaveCount(0)
        ->and(phase628Health($stamped->fresh())->codes())->not->toContain('cfdi_folio_mismatch');
});

test('si Facturapi timbra con otro folio de la serie del ERP, queda en el historial y en health sin revertir el timbrado', function () {
    $invoice = phase628DraftReady('FAC-00000019');
    phase628FakeStamp($invoice->pac_draft_external_id, ['series' => 'FAC', 'folio_number' => 99]);

    $stamped = app(StampPacDraftInvoiceService::class)->stamp($invoice);
    $events = phase628MismatchEvents($invoice);
    $finding = phase628Health($stamped->fresh())->find('cfdi_folio_mismatch');

    expect($stamped->pac_issue_status)->toBe('succeeded')
        ->and($stamped->cfdi_uuid)->toBe(PHASE628_UUID)
        ->and($events)->toHaveCount(1)
        ->and($events[0]->context)->toBe(['expected_folio' => 'FAC-19', 'received_folio' => 'FAC-99'])
        ->and($finding->status)->toBe(InvoiceHealthStatus::Invalid)
        ->and($finding->action)->toBe(InvoiceHealthAction::ManualReview);
});

test('si Facturapi ignora la serie (timbra con "F"), el historial lo registra', function () {
    $invoice = phase628DraftReady('FAC-00000019');
    phase628FakeStamp($invoice->pac_draft_external_id, ['series' => 'F', 'folio_number' => 10]);

    app(StampPacDraftInvoiceService::class)->stamp($invoice);

    expect(phase628MismatchEvents($invoice)->first()->context)->toBe(['expected_folio' => 'FAC-19', 'received_folio' => 'F-10']);
});

test('una respuesta sin folio no se puede verificar: no registra diferencia', function () {
    $invoice = phase628DraftReady('FAC-00000019');
    phase628FakeStamp($invoice->pac_draft_external_id, []);

    $stamped = app(StampPacDraftInvoiceService::class)->stamp($invoice);

    expect($stamped->pac_issue_status)->toBe('succeeded')
        ->and($invoice->fresh()->cfdi_folio_number)->toBeNull()
        ->and(phase628MismatchEvents($invoice))->toHaveCount(0);
});

test('una respuesta posterior sin serie ni folio nunca borra los ya guardados', function () {
    $invoice = phase628DraftReady();
    $invoice->forceFill(['pac_response' => ['id' => 'x', 'series' => 'FAC', 'folio_number' => 19]])->save();
    $invoice->forceFill(['pac_response' => ['id' => 'x', 'status' => 'canceled']])->save();

    expect($invoice->fresh()->cfdi_series)->toBe('FAC')
        ->and($invoice->fresh()->cfdi_folio_number)->toBe(19);
});

test('el folio guardado acepta el número como texto y descarta valores inválidos', function (mixed $number, ?int $expected) {
    $invoice = phase628DraftReady();
    $invoice->forceFill(['pac_response' => ['id' => 'x', 'series' => 'F', 'folio_number' => $number]])->save();

    expect($invoice->fresh()->cfdi_folio_number)->toBe($expected);
})->with([
    'entero' => [10, 10],
    'texto numérico' => ['10', 10],
    'cero' => [0, null],
    'negativo' => [-3, null],
    'texto' => ['diez', null],
]);

test('una factura anterior a la 6.28 (serie F) no se reporta en health', function () {
    $invoice = phase628DraftReady('FAC-00000019');
    $invoice->forceFill(['pac_response' => ['id' => 'x', 'series' => 'F', 'folio_number' => 10]])->save();

    expect(phase628Health($invoice->fresh())->codes())->not->toContain('cfdi_folio_mismatch');
});

test('el historial expone sólo el folio esperado y el recibido', function () {
    $invoice = phase628DraftReady('FAC-00000019');
    phase628FakeStamp($invoice->pac_draft_external_id, ['series' => 'FAC', 'folio_number' => 99]);
    app(StampPacDraftInvoiceService::class)->stamp($invoice);

    $public = (new InvoicePacEventResource(phase628MismatchEvents($invoice)->first()))->toArray(Request::create('/'));

    expect($public['type'])->toBe('cfdi_folio_mismatch')
        ->and($public['context'])->toBe(['expected_folio' => 'FAC-19', 'received_folio' => 'FAC-99']);
});
