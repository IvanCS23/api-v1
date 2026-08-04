<?php

use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Scopes\CompanyScope;
use App\Services\Billing\CreatePacDraftInvoiceService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * SERVICE (Fase 6.2.4): CreatePacDraftInvoiceService. Nunca llama
 * IssueInvoiceService; nunca modifica cfdi_uuid/stamped_at ni marca
 * pac_issue_status=succeeded — un borrador no es un CFDI.
 */
beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_CREATE_DRAFT_SERVICE',
    ]);

    if (method_exists(Http::class, 'preventStrayRequests')) {
        Http::preventStrayRequests();
    }
});

function issuedInvoiceForDraftServiceTest(): Invoice
{
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);

    app(CurrentTenant::class)->set($company->id);

    return $invoice->fresh(['items']);
}

function fakeDraftBodyFor(Invoice $invoice, array $overrides = []): array
{
    return array_merge([
        'id' => 'inv_draft_svc_'.$invoice->id,
        'status' => 'draft',
        'livemode' => false,
        'is_ready_to_stamp' => true,
        'created_at' => '2026-08-07T10:00:00Z',
    ], $overrides);
}

test('crea el borrador una sola vez: persiste pac_draft_* sin tocar cfdi_uuid/stamped_at/pac_issue_status', function () {
    $invoice = issuedInvoiceForDraftServiceTest();

    Http::fake(['*' => Http::response(fakeDraftBodyFor($invoice), 200)]);

    $updated = app(CreatePacDraftInvoiceService::class)->createOrSync($invoice);

    expect($updated->pac_draft_external_id)->toBe('inv_draft_svc_'.$invoice->id)
        ->and($updated->pac_draft_status)->toBe('draft')
        ->and($updated->pac_draft_ready_to_stamp)->toBeTrue()
        ->and($updated->pac_draft_idempotency_key)->toBe("erp-invoice-draft:{$invoice->company_id}:{$invoice->id}:v1")
        ->and($updated->pac_draft_created_at)->toBeInstanceOf(\Carbon\CarbonImmutable::class)
        ->and($updated->pac_draft_last_sync_at)->toBeInstanceOf(\Carbon\CarbonImmutable::class)
        ->and($updated->pac_provider)->toBe('facturapi')
        // nunca se toca nada de emisión final:
        ->and($updated->cfdi_uuid)->toBeNull()
        ->and($updated->stamped_at)->toBeNull()
        ->and($updated->pac_external_id)->toBeNull()
        ->and($updated->pac_issue_status)->toBeNull();
});

test('idempotencia local: un draft ya existente NO genera un segundo POST — se sincroniza en su lugar', function () {
    $invoice = issuedInvoiceForDraftServiceTest();
    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_draft_external_id' => 'inv_draft_existing',
        'pac_draft_status' => 'draft',
        'pac_draft_ready_to_stamp' => false,
    ])->save();

    Http::fake(['*' => Http::response([
        'id' => 'inv_draft_existing',
        'status' => 'draft',
        'livemode' => false,
        'is_ready_to_stamp' => true,
    ], 200)]);

    $updated = app(CreatePacDraftInvoiceService::class)->createOrSync($invoice->fresh());

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET');
    expect($updated->pac_draft_ready_to_stamp)->toBeTrue();
});

test('retry (misma Invoice, sin draft creado aún) reutiliza exactamente la misma idempotency_key de draft', function () {
    $invoice = issuedInvoiceForDraftServiceTest();
    $expectedKey = "erp-invoice-draft:{$invoice->company_id}:{$invoice->id}:v1";

    // Un segundo Http::fake() en la misma prueba NO reemplaza al primero
    // (Laravel acumula stubs) — se usa fakeSequence() para servir el 500
    // en el primer intento y el 200 en el segundo, determinísticamente.
    Http::fakeSequence()
        ->push(['message' => 'Error interno del PAC'], 500)
        ->push(fakeDraftBodyFor($invoice), 200);

    try {
        app(CreatePacDraftInvoiceService::class)->createOrSync($invoice);
        test()->fail('Se esperaba una excepción');
    } catch (\Throwable) {
        // esperado
    }

    $afterFirstAttempt = $invoice->fresh();
    expect($afterFirstAttempt->pac_draft_idempotency_key)->toBe($expectedKey)
        ->and($afterFirstAttempt->pac_draft_external_id)->toBeNull();

    $updated = app(CreatePacDraftInvoiceService::class)->createOrSync($afterFirstAttempt);

    expect($updated->pac_draft_idempotency_key)->toBe($expectedKey);

    Http::assertSent(fn ($request) => ($request->data()['idempotency_key'] ?? null) === $expectedKey);
});

test('aislamiento multi-tenant: no se puede crear un draft para una Invoice de otra empresa', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $foreignInvoice = Invoice::factory()->create(['company_id' => $companyB->id, 'status' => InvoiceStatus::Issued]);

    app(CurrentTenant::class)->set($companyA->id);

    Http::fake();

    expect(fn () => app(CreatePacDraftInvoiceService::class)->createOrSync($foreignInvoice))
        ->toThrow(ModelNotFoundException::class);

    Http::assertNothingSent();
});

test('rollback: si la persistencia final falla, no queda escritura parcial de pac_draft_*', function () {
    $company = Company::factory()->create();
    app(CurrentTenant::class)->set($company->id);

    $existing = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    $existing->forceFill(['pac_provider' => 'facturapi', 'pac_draft_external_id' => 'inv_draft_conflict'])->save();

    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);

    // Mismo company_id + pac_provider + pac_draft_external_id que
    // $existing => viola erp_invoices_pac_draft_external_unique.
    Http::fake(['*' => Http::response([
        'id' => 'inv_draft_conflict',
        'status' => 'draft',
        'livemode' => false,
        'is_ready_to_stamp' => true,
    ], 200)]);

    expect(fn () => app(CreatePacDraftInvoiceService::class)->createOrSync($invoice->fresh(['items'])))
        ->toThrow(\Illuminate\Database\QueryException::class);

    $fresh = $invoice->fresh();
    expect($fresh->pac_draft_external_id)->toBeNull()
        ->and($fresh->pac_draft_status)->toBeNull()
        ->and($fresh->pac_draft_ready_to_stamp)->toBeNull();
});

test('readiness local bloquea antes de cualquier HTTP si la Invoice no está lista fiscalmente', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Issued,
        'payment_form' => null,
    ]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);
    app(CurrentTenant::class)->set($company->id);

    Http::fake();

    expect(fn () => app(CreatePacDraftInvoiceService::class)->createOrSync($invoice->fresh(['items'])))
        ->toThrow(\App\Exceptions\Billing\InvoiceFiscalSnapshotIncompleteException::class);

    Http::assertNothingSent();
});

test('una respuesta atrasada no sobrescribe un draft ya persistido por otra ejecución (defensa bajo carrera)', function () {
    $invoice = issuedInvoiceForDraftServiceTest();

    Http::fake(function () use ($invoice) {
        // Simula que otra ejecución ya completó y persistió su propio
        // draft justo después de que llamamos al PAC pero antes de
        // nuestro commit.
        Invoice::withoutGlobalScope(CompanyScope::class)->whereKey($invoice->id)->update([
            'pac_provider' => 'facturapi',
            'pac_draft_external_id' => 'inv_draft_winner',
            'pac_draft_status' => 'draft',
        ]);

        return Http::response(fakeDraftBodyFor($invoice, ['id' => 'inv_draft_loser']), 200);
    });

    $result = app(CreatePacDraftInvoiceService::class)->createOrSync($invoice);

    expect($result->pac_draft_external_id)->toBe('inv_draft_winner');
});
