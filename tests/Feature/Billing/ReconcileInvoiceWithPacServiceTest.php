<?php

use App\Enums\InvoiceStatus;
use App\Exceptions\Billing\PacAmbiguousInvoiceMatchException;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\Billing\ReconcileInvoiceWithPacService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;

/**
 * ReconcileInvoiceWithPacService (Fase 6.2.1, funcional desde Fase
 * 6.2.2): ya no está bloqueada por la ausencia de `pac_external_id` — el
 * caso típico (respuesta ambigua ANTES de recibir el `id` del PAC) ahora
 * se resuelve reconstruyendo `external_id` de forma determinista
 * (idéntica fórmula que IssueInvoiceService usó al reservar, ver
 * PacIdentifiers) y consultando `findInvoiceByExternalId()`.
 */
beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_RECONCILE',
    ]);

    Http::preventStrayRequests();
});

function reconciliationRequiredInvoice(Company $company): Invoice
{
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_idempotency_key' => "erp-invoice:{$company->id}:{$invoice->id}:v1",
        'pac_issue_status' => 'reconciliation_required',
        'pac_reconciliation_required' => true,
        'pac_issue_attempts' => 1,
        'cfdi_artifacts_status' => 'stored',
    ])->save();

    return $invoice->fresh();
}

test('multi-tenant: no se puede reconciliar una factura que pertenece a otra empresa', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $invoice = reconciliationRequiredInvoice($companyB);

    app(CurrentTenant::class)->set($companyA->id);

    expect(fn () => app(ReconcileInvoiceWithPacService::class)->reconcile($invoice))
        ->toThrow(ModelNotFoundException::class);
});

test('una Invoice sin contexto de emisión (nunca se intentó emitir) no tiene nada que reconciliar', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    app(CurrentTenant::class)->set($company->id);

    expect(fn () => app(ReconcileInvoiceWithPacService::class)->reconcile($invoice))
        ->toThrow(RuntimeException::class);
});

test('con pac_external_id conocido, usa retrieveInvoice() (consulta directa por id del PAC)', function () {
    $company = Company::factory()->create();
    $invoice = reconciliationRequiredInvoice($company);
    $invoice->forceFill(['pac_external_id' => 'inv_found_manually'])->save();
    app(CurrentTenant::class)->set($company->id);

    Http::fake(['*' => Http::response([
        'id' => 'inv_found_manually',
        'livemode' => false,
        'status' => 'valid',
        'uuid' => 'EEEEEEEE-1111-2222-3333-444444444444',
        'stamp' => ['date' => '2026-07-30T10:00:00Z'],
    ], 200)]);

    $result = app(ReconcileInvoiceWithPacService::class)->reconcile($invoice->fresh());

    Http::assertSent(fn ($request) => str_contains($request->url(), '/invoices/inv_found_manually')
        && $request->method() === 'GET');

    expect($result->cfdi_uuid)->toBe('EEEEEEEE-1111-2222-3333-444444444444')
        ->and($result->pac_status)->toBe('valid')
        ->and($result->pac_issue_status)->toBe('succeeded')
        ->and($result->pac_reconciliation_required)->toBeFalse()
        ->and($result->pac_last_error)->toBeNull();
});

test('con pac_external_id desconocido, reconstruye external_id determinista y usa findInvoiceByExternalId()', function () {
    $company = Company::factory()->create();
    $invoice = reconciliationRequiredInvoice($company);
    $expectedExternalId = "company-{$company->id}-invoice-{$invoice->id}";
    app(CurrentTenant::class)->set($company->id);

    Http::fake(['*' => Http::response([
        'page' => 1,
        'total_pages' => 1,
        'total_results' => 1,
        'data' => [[
            'id' => 'inv_via_search',
            'livemode' => false,
            'status' => 'valid',
            'uuid' => 'FFFFFFFF-1111-2222-3333-444444444444',
        ]],
    ], 200)]);

    $result = app(ReconcileInvoiceWithPacService::class)->reconcile($invoice);

    Http::assertSent(function ($request) use ($expectedExternalId) {
        return $request->method() === 'GET'
            && str_contains($request->url(), '/invoices')
            && ! str_contains(parse_url($request->url(), PHP_URL_PATH), 'inv_')
            && ($request->data()['external_id'] ?? null) === $expectedExternalId;
    });

    expect($result->pac_external_id)->toBe('inv_via_search')
        ->and($result->cfdi_uuid)->toBe('FFFFFFFF-1111-2222-3333-444444444444')
        ->and($result->pac_issue_status)->toBe('succeeded')
        ->and($result->pac_reconciliation_required)->toBeFalse();
});

test('no encontrada: sigue reconciliation_required, no se asume que nunca existió, no se crea ninguna factura', function () {
    $company = Company::factory()->create();
    $invoice = reconciliationRequiredInvoice($company);
    app(CurrentTenant::class)->set($company->id);

    $countBefore = Invoice::count();

    Http::fake(['*' => Http::response(['page' => 1, 'total_pages' => 1, 'total_results' => 0, 'data' => []], 200)]);

    $result = app(ReconcileInvoiceWithPacService::class)->reconcile($invoice);

    expect($result->pac_reconciliation_required)->toBeTrue()
        ->and($result->pac_issue_status)->toBe('reconciliation_required')
        ->and($result->cfdi_uuid)->toBeNull()
        ->and(Invoice::count())->toBe($countBefore);
});

test('múltiples coincidencias de external_id: sigue reconciliation_required, nunca elige una en silencio', function () {
    $company = Company::factory()->create();
    $invoice = reconciliationRequiredInvoice($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake(['*' => Http::response([
        'page' => 1,
        'total_pages' => 1,
        'total_results' => 2,
        'data' => [
            ['id' => 'inv_dup_1', 'status' => 'valid'],
            ['id' => 'inv_dup_2', 'status' => 'valid'],
        ],
    ], 200)]);

    $result = app(ReconcileInvoiceWithPacService::class)->reconcile($invoice);

    expect($result->pac_reconciliation_required)->toBeTrue()
        ->and($result->pac_external_id)->toBeNull()
        ->and($result->pac_last_error)->not->toBeNull()
        ->and($result->pac_last_error)->not->toContain('inv_dup_1')
        ->and($result->pac_last_error)->not->toContain('inv_dup_2');
});

test('error de red/5xx al buscar por external_id: sigue reconciliation_required, no modifica datos PAC válidos existentes', function () {
    $company = Company::factory()->create();
    $invoice = reconciliationRequiredInvoice($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake(['*' => Http::response(['message' => 'Error interno del PAC'], 500)]);

    $result = app(ReconcileInvoiceWithPacService::class)->reconcile($invoice);

    expect($result->pac_reconciliation_required)->toBeTrue()
        ->and($result->pac_last_error)->not->toBeNull()
        ->and($result->cfdi_uuid)->toBeNull();
});

test('nunca llama createInvoice(): ni con pac_external_id conocido ni al buscar por external_id', function () {
    $company = Company::factory()->create();
    $invoice = reconciliationRequiredInvoice($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake(['*' => Http::response(['page' => 1, 'total_pages' => 1, 'total_results' => 0, 'data' => []], 200)]);

    app(ReconcileInvoiceWithPacService::class)->reconcile($invoice);

    Http::assertSent(fn ($request) => $request->method() === 'GET');
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

test('con pac_external_id presente, nunca crea una Invoice nueva — solo actualiza la existente', function () {
    $company = Company::factory()->create();
    $invoice = reconciliationRequiredInvoice($company);
    $invoice->forceFill(['pac_external_id' => 'inv_count_check'])->save();
    app(CurrentTenant::class)->set($company->id);

    $countBefore = Invoice::count();

    Http::fake(['*' => Http::response([
        'id' => 'inv_count_check',
        'livemode' => false,
        'status' => 'valid',
        'uuid' => '11111111-2222-3333-4444-555555555555',
    ], 200)]);

    app(ReconcileInvoiceWithPacService::class)->reconcile($invoice->fresh());

    expect(Invoice::count())->toBe($countBefore);
});

test('si retrieveInvoice() también falla, se registra el error pero reconciliation_required permanece true', function () {
    $company = Company::factory()->create();
    $invoice = reconciliationRequiredInvoice($company);
    $invoice->forceFill(['pac_external_id' => 'inv_not_found'])->save();
    app(CurrentTenant::class)->set($company->id);

    Http::fake(['*' => Http::response(['message' => 'No encontrado'], 404)]);

    $result = app(ReconcileInvoiceWithPacService::class)->reconcile($invoice->fresh());

    expect($result->pac_reconciliation_required)->toBeTrue()
        ->and($result->pac_last_error)->not->toBeNull();
});

test('no sobrescribe una Invoice ya reconciliada/emitida por otra ejecución concurrente', function () {
    $company = Company::factory()->create();
    $invoice = reconciliationRequiredInvoice($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake(function () use ($invoice) {
        // Simula que otra ejecución (reconciliación o emisión) ya
        // resolvió esta misma Invoice justo antes de nuestro lock.
        Invoice::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->whereKey($invoice->id)->update([
            'pac_external_id' => 'inv_winner',
            'cfdi_uuid' => 'AAAAAAAA-0000-0000-0000-000000000000',
            'pac_issue_status' => 'succeeded',
            'pac_reconciliation_required' => false,
        ]);

        return Http::response([
            'page' => 1, 'total_pages' => 1, 'total_results' => 1,
            'data' => [[
                'id' => 'inv_late_response',
                'livemode' => false,
                'status' => 'valid',
                'uuid' => 'BBBBBBBB-1111-2222-3333-444444444444',
            ]],
        ], 200);
    });

    $result = app(ReconcileInvoiceWithPacService::class)->reconcile($invoice);

    expect($result->pac_external_id)->toBe('inv_winner')
        ->and($result->cfdi_uuid)->toBe('AAAAAAAA-0000-0000-0000-000000000000');
});

test('persistencia con rollback: si la escritura del resultado encontrado falla, no deja campos parciales y no pierde el estado reconciliation_required', function () {
    $company = Company::factory()->create();
    app(CurrentTenant::class)->set($company->id);

    $existing = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    $existing->forceFill(['pac_provider' => 'facturapi', 'pac_external_id' => 'inv_conflict'])->save();

    $invoice = reconciliationRequiredInvoice($company);

    // Mismo company_id + pac_provider + pac_external_id que $existing =>
    // viola erp_invoices_pac_provider_external_unique al guardar.
    Http::fake(['*' => Http::response([
        'page' => 1, 'total_pages' => 1, 'total_results' => 1,
        'data' => [[
            'id' => 'inv_conflict',
            'livemode' => false,
            'status' => 'valid',
            'uuid' => 'CCCCCCCC-1111-2222-3333-444444444444',
        ]],
    ], 200)]);

    expect(fn () => app(ReconcileInvoiceWithPacService::class)->reconcile($invoice))
        ->toThrow(\Illuminate\Database\QueryException::class);

    $fresh = $invoice->fresh();
    expect($fresh->pac_external_id)->toBeNull()
        ->and($fresh->cfdi_uuid)->toBeNull()
        ->and($fresh->pac_issue_status)->toBe('reconciliation_required');
});
