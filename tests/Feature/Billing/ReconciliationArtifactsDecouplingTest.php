<?php

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Billing\InvoiceBusinessCapabilitiesService;
use App\Services\Billing\ReconcileInvoiceWithPacService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Fase 6.22: la revisión fiscal (pac_reconciliation_required) ya no depende
 * de si el XML/PDF se descargó. Antes, reconciliar un CFDI valid sin
 * artifacts dejaba la marca activa: la emisión orquestada por la ruta del
 * borrador valid respondía 409 con el CFDI ya válido y "Cancelar factura"
 * desaparecía. La ausencia de archivos la cubren cfdi_artifacts_missing,
 * can_fetch_artifacts y la descarga automática (Fase 6.21).
 */
beforeEach(function () {
    facturapiOrganizationsFromConfigKey();
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_PHASE_622_DECOUPLE',
    ]);

    Http::preventStrayRequests();
});

/** @return array{Invoice, User} */
function phase622Invoice(array $attributes = [], InvoiceStatus $status = InvoiceStatus::Ready): array
{
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Admin]);
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'status' => $status,
        'subtotal' => 100,
        'tax_total' => 0,
        'total' => 100,
    ]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id, 'subtotal' => 100, 'total' => 100]);
    $invoice->forceFill($attributes)->save();
    app(CurrentTenant::class)->set($company->id);

    return [$invoice->fresh(), $user];
}

function phase622ValidRemote(string $id, string $uuid = 'AAAAAAAA-2222-4222-8333-444444444444'): array
{
    return [
        'id' => $id,
        'status' => 'valid',
        'livemode' => false,
        'uuid' => $uuid,
        'stamp' => ['date' => '2026-10-21T10:00:00'],
    ];
}

test('operations/issue por la ruta del borrador valid responde 200 sin artifacts descargados', function () {
    [$invoice, $user] = phase622Invoice([
        'pac_provider' => 'facturapi',
        'pac_draft_external_id' => 'inv_622_draft',
        'pac_draft_idempotency_key' => 'erp-invoice-draft:x:v1',
        'pac_draft_status' => 'draft',
    ]);
    Http::fake(['*/invoices/inv_622_draft' => Http::response(phase622ValidRemote('inv_622_draft'))]);
    app(CurrentTenant::class)->clear();

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/operations/issue", ['confirm' => true])
        ->assertOk()
        ->assertJsonPath('status', 'issued')
        ->assertJsonPath('pac.status', 'valid')
        ->assertJsonPath('pac.reconciliation_required', false)
        ->assertJsonPath('cfdi.artifacts.xml_available', false)
        ->assertJsonPath('actions.can_cancel', true)
        ->assertJsonPath('actions.can_fetch_artifacts', true);

    Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
});

test('reconciliar un CFDI valid sin artifacts no deja revisión y conserva la cancelación', function () {
    [$invoice, $user] = phase622Invoice([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_622_valid',
        'cfdi_uuid' => 'AAAAAAAA-2222-4222-8333-444444444444',
        'pac_status' => 'valid',
        'pac_issue_status' => 'succeeded',
        'cancellation_status' => 'none',
        'pac_reconciliation_required' => true,
        'cfdi_artifacts_status' => null,
    ], InvoiceStatus::Issued);
    Http::fake(['*/invoices/inv_622_valid' => Http::response(phase622ValidRemote('inv_622_valid'))]);

    $result = app(ReconcileInvoiceWithPacService::class)->reconcile($invoice);
    $actions = app(InvoiceBusinessCapabilitiesService::class)->for($result, $user);

    expect($result->pac_reconciliation_required)->toBeFalse()
        ->and($result->cfdi_artifacts_status)->toBeNull()
        ->and($actions['can_cancel'])->toBeTrue()
        ->and($actions['cancellation_mode'])->toBe('pac')
        ->and($actions['can_reconcile'])->toBeFalse()
        ->and($actions['can_fetch_artifacts'])->toBeTrue();
});

test('la revisión fiscal se mantiene cuando el remoto no es final', function () {
    [$invoice] = phase622Invoice([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_622_pending',
        'pac_issue_status' => 'reconciliation_required',
        'pac_reconciliation_required' => true,
    ], InvoiceStatus::Issued);
    Http::fake(['*/invoices/inv_622_pending' => Http::response([
        'id' => 'inv_622_pending',
        'status' => 'pending',
        'livemode' => false,
    ])]);

    expect(app(ReconcileInvoiceWithPacService::class)->reconcile($invoice)->pac_reconciliation_required)->toBeTrue();
});
