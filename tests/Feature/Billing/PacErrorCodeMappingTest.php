<?php

use App\Contracts\Billing\PacProvider;
use App\Enums\CfdiCancellationMotive;
use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Exceptions\Billing\PacConflictException;
use App\Exceptions\Billing\PacInvoiceNotFoundException;
use App\Exceptions\Billing\PacOperationInProgressException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Billing\CancelInvoiceWithPacService;
use App\Services\Billing\ReconcileInvoiceWithPacService;
use App\Services\Billing\StampPacDraftInvoiceService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Support\Facades\Http;

/**
 * Fase 6.20: los códigos estables de Facturapi (`body.code`) se mapean
 * antes que el HTTP status, que Facturapi no documenta por código.
 * - invoice_not_found → fallo DEFINITIVO.
 * - stamping_in_progress, invoice_cancellation_in_progress,
 *   draft_update_in_progress, idempotency_key_in_use → AMBIGUO.
 */
beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_PHASE_620_SECRET',
    ]);

    Http::preventStrayRequests();
});

function phase620ErrorBody(string $code): array
{
    return ['ok' => false, 'message' => 'Mensaje crudo del PAC', 'code' => $code];
}

function phase620StampableInvoice(): Invoice
{
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_draft_external_id' => 'inv_draft_620_'.$invoice->id,
        'pac_draft_idempotency_key' => "erp-invoice-draft:{$company->id}:{$invoice->id}:v1",
        'pac_draft_status' => 'draft',
        'pac_draft_ready_to_stamp' => true,
    ])->save();
    app(CurrentTenant::class)->set($company->id);

    return $invoice->fresh();
}

function phase620FakeStampError(Invoice $invoice, string $code, int $status): void
{
    Http::fake([
        "*/invoices/{$invoice->pac_draft_external_id}" => Http::response([
            'id' => $invoice->pac_draft_external_id,
            'status' => 'draft',
            'livemode' => false,
            'is_ready_to_stamp' => true,
        ], 200),
        "*/invoices/{$invoice->pac_draft_external_id}/stamp" => Http::response(phase620ErrorBody($code), $status),
    ]);
}

function phase620CancelableInvoice(): Invoice
{
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_cancel_620_'.$invoice->id,
        'cfdi_uuid' => 'fced601d-c4f6-4ce7-8f05-f3d38de530f9',
        'pac_status' => 'valid',
        'cancellation_status' => 'none',
        'pac_issue_status' => 'succeeded',
        'pac_reconciliation_required' => false,
        'cfdi_artifacts_status' => 'stored',
    ])->save();
    app(CurrentTenant::class)->set($company->id);

    return $invoice->fresh();
}

// ==================== FacturapiProvider ====================

test('invoice_not_found se mapea a PacInvoiceNotFoundException sin importar el HTTP status', function (int $status) {
    Http::fake(['*' => Http::response(phase620ErrorBody('invoice_not_found'), $status)]);

    try {
        app(PacProvider::class)->retrieveInvoice('inv_missing');
        $this->fail('Se esperaba PacInvoiceNotFoundException.');
    } catch (PacInvoiceNotFoundException $e) {
        expect($e->httpStatus)->toBe($status)
            ->and($e->pacCode)->toBe('invoice_not_found');
    }
})->with([404, 400]);

test('los códigos de operación en curso se mapean a PacOperationInProgressException (ambigua) sin importar el status', function (string $code, int $status) {
    Http::fake(['*' => Http::response(phase620ErrorBody($code), $status)]);

    try {
        app(PacProvider::class)->stampDraftInvoice('inv_busy');
        $this->fail('Se esperaba PacOperationInProgressException.');
    } catch (PacOperationInProgressException $e) {
        expect($e)->toBeInstanceOf(PacConflictException::class)
            ->and($e->pacCode)->toBe($code)
            ->and($e->httpStatus)->toBe($status);
    }
})->with([
    'stamping_in_progress',
    'invoice_cancellation_in_progress',
    'draft_update_in_progress',
    'idempotency_key_in_use',
])->with([409, 400]);

test('un código desconocido conserva el mapeo por HTTP status', function () {
    Http::fake(['*' => Http::response(phase620ErrorBody('some_future_code'), 409)]);

    try {
        app(PacProvider::class)->stampDraftInvoice('inv_unknown');
        $this->fail('Se esperaba PacConflictException.');
    } catch (PacConflictException $e) {
        expect($e)->not->toBeInstanceOf(PacOperationInProgressException::class);
    }
});

// ==================== Timbrado ====================

test('invoice_not_found en /stamp es DEFINITIVO: pac_issue_status=failed sin reconciliación', function () {
    $invoice = phase620StampableInvoice();
    phase620FakeStampError($invoice, 'invoice_not_found', 404);

    expect(fn () => app(StampPacDraftInvoiceService::class)->stamp($invoice))
        ->toThrow(PacInvoiceNotFoundException::class);

    $fresh = $invoice->fresh();
    expect($fresh->pac_issue_status)->toBe('failed')
        ->and($fresh->pac_reconciliation_required)->toBeFalse()
        ->and($fresh->pac_last_error)->toContain('invoice_not_found')
        ->and($fresh->pac_draft_external_id)->toBe($invoice->pac_draft_external_id);
});

test('stamping_in_progress en /stamp es AMBIGUO aunque llegue con 400 (antes se trataba como validación definitiva)', function () {
    $invoice = phase620StampableInvoice();
    phase620FakeStampError($invoice, 'stamping_in_progress', 400);

    expect(fn () => app(StampPacDraftInvoiceService::class)->stamp($invoice))
        ->toThrow(PacOperationInProgressException::class);

    $fresh = $invoice->fresh();
    expect($fresh->pac_issue_status)->toBe('reconciliation_required')
        ->and($fresh->pac_reconciliation_required)->toBeTrue()
        ->and($fresh->cfdi_uuid)->toBeNull();
});

// ==================== Cancelación ====================

test('invoice_not_found en DELETE es DEFINITIVO: solicitud failed, ERP y CFDI intactos', function () {
    $invoice = phase620CancelableInvoice();
    Http::fake(["*/invoices/{$invoice->pac_external_id}*" => Http::response(phase620ErrorBody('invoice_not_found'), 404)]);

    expect(fn () => app(CancelInvoiceWithPacService::class)->cancel($invoice, CfdiCancellationMotive::ErrorsWithoutRelation))
        ->toThrow(PacInvoiceNotFoundException::class);

    $fresh = $invoice->fresh();
    expect($fresh->pac_cancel_request_status)->toBe(CancelInvoiceWithPacService::REQUEST_FAILED)
        ->and($fresh->pac_reconciliation_required)->toBeFalse()
        ->and($fresh->status)->toBe(InvoiceStatus::Issued)
        ->and($fresh->pac_status)->toBe('valid');
});

test('invoice_cancellation_in_progress en DELETE es AMBIGUO aunque llegue con 400', function () {
    $invoice = phase620CancelableInvoice();
    Http::fake(["*/invoices/{$invoice->pac_external_id}*" => Http::response(phase620ErrorBody('invoice_cancellation_in_progress'), 400)]);

    expect(fn () => app(CancelInvoiceWithPacService::class)->cancel($invoice, CfdiCancellationMotive::ErrorsWithoutRelation))
        ->toThrow(PacOperationInProgressException::class);

    $fresh = $invoice->fresh();
    expect($fresh->pac_cancel_request_status)->toBe(CancelInvoiceWithPacService::REQUEST_RECONCILIATION_REQUIRED)
        ->and($fresh->pac_reconciliation_required)->toBeTrue()
        ->and($fresh->status)->toBe(InvoiceStatus::Issued)
        ->and($fresh->pac_status)->toBe('valid');
});

// ==================== Reconciliación ====================

test('invoice_not_found al reconciliar no libera la revisión: puede ser una llave de otra organización', function () {
    $invoice = phase620CancelableInvoice();
    $invoice->forceFill(['pac_reconciliation_required' => true])->save();
    Http::fake(["*/invoices/{$invoice->pac_external_id}" => Http::response(phase620ErrorBody('invoice_not_found'), 404)]);

    $result = app(ReconcileInvoiceWithPacService::class)->reconcile($invoice->fresh());

    expect($result->pac_reconciliation_required)->toBeTrue()
        ->and($result->pac_external_id)->toBe($invoice->pac_external_id)
        ->and($result->cfdi_uuid)->toBe($invoice->cfdi_uuid)
        ->and($result->pac_status)->toBe('valid');
});

// ==================== Contrato público ====================

test('operations/issue responde 409 PAC_OPERATION_IN_PROGRESS ante idempotency_key_in_use, sin el mensaje crudo del PAC', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Admin]);
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Ready,
        'subtotal' => 100,
        'tax_total' => 0,
        'total' => 100,
    ]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id, 'subtotal' => 100, 'total' => 100]);
    app(CurrentTenant::class)->set($company->id);

    Http::fake(['*/v2/invoices' => Http::response(phase620ErrorBody('idempotency_key_in_use'), 409)]);

    $response = $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/operations/issue", ['confirm' => true])
        ->assertStatus(409)
        ->assertJsonPath('code', 'PAC_OPERATION_IN_PROGRESS');

    expect($response->getContent())->not->toContain('Mensaje crudo del PAC');
});

test('operations/cancel responde 409 PAC_INVOICE_NOT_FOUND y el ERP no converge', function () {
    $invoice = phase620CancelableInvoice();
    $user = User::factory()->create(['company_id' => $invoice->company_id, 'role' => UserRole::Admin]);
    Http::fake(["*/invoices/{$invoice->pac_external_id}*" => Http::response(phase620ErrorBody('invoice_not_found'), 404)]);

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/operations/cancel", ['confirm' => true, 'motive' => '02'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'PAC_INVOICE_NOT_FOUND');

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Issued);
});
