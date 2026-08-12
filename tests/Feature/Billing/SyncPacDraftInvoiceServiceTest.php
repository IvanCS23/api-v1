<?php

use App\Enums\InvoicePacEventType;
use App\Enums\InvoiceStatus;
use App\Exceptions\Billing\PacUnexpectedEnvironmentException;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\Billing\SyncPacDraftInvoiceService;
use App\Support\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;

/**
 * SERVICE (Fase 6.2.4): SyncPacDraftInvoiceService. Nunca timbra —
 * únicamente refleja localmente lo que Facturapi reporta.
 */
beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_SYNC_DRAFT_SERVICE',
    ]);

    if (method_exists(Http::class, 'preventStrayRequests')) {
        Http::preventStrayRequests();
    }
});

function draftedInvoiceForSyncTest(Company $company, array $overrides = []): Invoice
{
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    $invoice->forceFill(array_merge([
        'pac_provider' => 'facturapi',
        'pac_draft_external_id' => 'inv_draft_sync_'.$invoice->id,
        'pac_draft_idempotency_key' => "erp-invoice-draft:{$company->id}:{$invoice->id}:v1",
        'pac_draft_status' => 'draft',
        'pac_draft_ready_to_stamp' => false,
    ], $overrides))->save();

    return $invoice->fresh();
}

test('sincroniza el estado real del draft: status, is_ready_to_stamp y last_sync_at', function () {
    $company = Company::factory()->create();
    $invoice = draftedInvoiceForSyncTest($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake(['*' => Http::response([
        'id' => $invoice->pac_draft_external_id,
        'status' => 'draft',
        'livemode' => false,
        'is_ready_to_stamp' => true,
    ], 200)]);

    $updated = app(SyncPacDraftInvoiceService::class)->sync($invoice);

    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && str_ends_with($request->url(), $invoice->pac_draft_external_id));

    expect($updated->pac_draft_ready_to_stamp)->toBeTrue()
        ->and($updated->pac_draft_status)->toBe('draft')
        ->and($updated->pac_draft_last_sync_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($updated->pacEvents()->pluck('event_type')->all())->toBe([
            InvoicePacEventType::DraftSynced,
        ]);
});

test('nunca timbra: is_ready_to_stamp=true solo se refleja localmente, sin tocar cfdi_uuid/pac_issue_status', function () {
    $company = Company::factory()->create();
    $invoice = draftedInvoiceForSyncTest($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake(['*' => Http::response([
        'id' => $invoice->pac_draft_external_id,
        'status' => 'draft',
        'livemode' => false,
        'is_ready_to_stamp' => true,
    ], 200)]);

    $updated = app(SyncPacDraftInvoiceService::class)->sync($invoice);

    expect($updated->pac_draft_ready_to_stamp)->toBeTrue()
        ->and($updated->cfdi_uuid)->toBeNull()
        ->and($updated->stamped_at)->toBeNull()
        ->and($updated->pac_external_id)->toBeNull()
        ->and($updated->pac_issue_status)->toBeNull();

    Http::assertSentCount(1);
});

test('sin pac_draft_external_id, falla localmente sin hacer ninguna llamada HTTP', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    app(CurrentTenant::class)->set($company->id);

    Http::fake();

    expect(fn () => app(SyncPacDraftInvoiceService::class)->sync($invoice))
        ->toThrow(RuntimeException::class);

    Http::assertNothingSent();
});

test('aislamiento multi-tenant: no se puede sincronizar el draft de una Invoice de otra empresa', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $foreignInvoice = draftedInvoiceForSyncTest($companyB);

    app(CurrentTenant::class)->set($companyA->id);

    Http::fake();

    expect(fn () => app(SyncPacDraftInvoiceService::class)->sync($foreignInvoice))
        ->toThrow(ModelNotFoundException::class);

    Http::assertNothingSent();
});

test('livemode=true durante la sincronización bloquea y no persiste el resultado', function () {
    $company = Company::factory()->create();
    $invoice = draftedInvoiceForSyncTest($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake(['*' => Http::response([
        'id' => $invoice->pac_draft_external_id,
        'status' => 'draft',
        'livemode' => true,
        'is_ready_to_stamp' => true,
    ], 200)]);

    expect(fn () => app(SyncPacDraftInvoiceService::class)->sync($invoice))
        ->toThrow(PacUnexpectedEnvironmentException::class);

    $fresh = $invoice->fresh();
    expect($fresh->pac_draft_ready_to_stamp)->toBeFalse() // valor original, sin cambios
        ->and($fresh->pac_draft_last_sync_at)->toBeNull();
});
