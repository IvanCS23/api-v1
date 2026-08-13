<?php

use App\Contracts\Billing\PacProvider;
use App\Data\Billing\PacInvoiceResult;
use App\Enums\InvoicePacEventType;
use App\Enums\InvoiceStatus;
use App\Events\Billing\InvoiceIssued;
use App\Exceptions\Billing\PacAuthenticationException;
use App\Exceptions\Billing\PacConflictException;
use App\Exceptions\Billing\PacRateLimitException;
use App\Exceptions\Billing\PacUnavailableException;
use App\Exceptions\Billing\PacUnexpectedEnvironmentException;
use App\Exceptions\Billing\PacUnexpectedResponseException;
use App\Exceptions\Billing\PacValidationException;
use App\Exceptions\InvoiceAlreadyIssuedException;
use App\Exceptions\InvoiceDraftNotReadyToStampException;
use App\Exceptions\InvoiceIssuanceInProgressException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoicePacEvent;
use App\Models\Scopes\CompanyScope;
use App\Services\Billing\InvoicePacAuditService;
use App\Services\Billing\StampPacDraftInvoiceService;
use App\Support\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * StampPacDraftInvoiceService (Fase 6.2.5): timbrado explícito de un
 * Draft remoto ya existente mediante `POST /invoices/{id}/stamp`. Nunca
 * crea un CFDI nuevo (`createInvoice()`); el recurso identificado por
 * `pac_draft_external_id` se transforma en la factura timbrada. Reutiliza
 * el tracking `pac_issue_*` de Fase 6.2.1 — no existen columnas
 * `pac_stamp_*` (ver docblock de StampPacDraftInvoiceService para la
 * justificación completa de esa decisión).
 */
beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_STAMP_SERVICE',
    ]);

    if (method_exists(Http::class, 'preventStrayRequests')) {
        Http::preventStrayRequests();
    }
});

function draftReadyInvoiceForStampTest(Company $company, array $overrides = []): Invoice
{
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    $invoice->forceFill(array_merge([
        'pac_provider' => 'facturapi',
        'pac_draft_external_id' => 'inv_draft_stamp_'.$invoice->id,
        'pac_draft_idempotency_key' => "erp-invoice-draft:{$company->id}:{$invoice->id}:v1",
        'pac_draft_status' => 'draft',
        'pac_draft_ready_to_stamp' => true,
    ], $overrides))->save();

    return $invoice->fresh();
}

function fakeSyncedReadyDraftBody(Invoice $invoice, array $overrides = []): array
{
    return array_merge([
        'id' => $invoice->pac_draft_external_id,
        'status' => 'draft',
        'livemode' => false,
        'is_ready_to_stamp' => true,
    ], $overrides);
}

function fakeStampSuccessBody(Invoice $invoice, array $overrides = []): array
{
    return array_merge([
        'id' => $invoice->pac_draft_external_id,
        'status' => 'valid',
        'livemode' => false,
        'uuid' => 'BBBBBBBB-1111-2222-3333-444444444444',
        'stamp' => ['date' => '2026-08-07T12:00:00Z'],
    ], $overrides);
}

// ==================== CONTRACT (FacturapiProvider::stampDraftInvoice) ====================

test('stampDraftInvoice existe en el contrato PacProvider', function () {
    expect(method_exists(PacProvider::class, 'stampDraftInvoice'))->toBeTrue();
});

test('stampDraftInvoice llama POST /invoices/{id}/stamp, nunca envía async=true', function () {
    Http::fake(['*' => Http::response(['id' => 'inv_contract_1', 'status' => 'valid', 'livemode' => false], 200)]);

    app(PacProvider::class)->stampDraftInvoice('inv_contract_1');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/invoices/inv_contract_1/stamp')
        && ! str_contains($request->body() ?? '', 'async'));
});

test('el DTO devuelto es PacInvoiceResult, nunca PacInvoiceDraftResult', function () {
    Http::fake(['*' => Http::response([
        'id' => 'inv_contract_2',
        'status' => 'valid',
        'uuid' => 'AAAAAAAA-1111-2222-3333-444444444444',
        'livemode' => false,
    ], 200)]);

    $result = app(PacProvider::class)->stampDraftInvoice('inv_contract_2');

    expect($result)->toBeInstanceOf(PacInvoiceResult::class)
        ->and($result->externalId)->toBe('inv_contract_2')
        ->and($result->status)->toBe('valid')
        ->and($result->uuid)->toBe('AAAAAAAA-1111-2222-3333-444444444444');
});

test('una respuesta sin id/status produce PacUnexpectedResponseException', function () {
    Http::fake(['*' => Http::response(['livemode' => false], 200)]);

    expect(fn () => app(PacProvider::class)->stampDraftInvoice('inv_missing_fields'))
        ->toThrow(PacUnexpectedResponseException::class);
});

test('ausencia del campo livemode produce PacUnexpectedResponseException (nunca se asume false)', function () {
    Http::fake(['*' => Http::response(['id' => 'inv_no_livemode', 'status' => 'valid'], 200)]);

    expect(fn () => app(PacProvider::class)->stampDraftInvoice('inv_no_livemode'))
        ->toThrow(PacUnexpectedResponseException::class);
});

test('livemode=true bloquea con PacUnexpectedEnvironmentException, nunca se interpreta como timbrado TEST', function () {
    Http::fake(['*' => Http::response(['id' => 'inv_live', 'status' => 'valid', 'livemode' => true], 200)]);

    try {
        app(PacProvider::class)->stampDraftInvoice('inv_live');
        test()->fail('Se esperaba PacUnexpectedEnvironmentException');
    } catch (PacUnexpectedEnvironmentException $e) {
        expect($e->remoteId)->toBe('inv_live')
            ->and($e->context)->toBe('stampDraftInvoice');
    }
});

test('409 se mapea a PacConflictException, nunca a un error de validación genérico', function () {
    Http::fake(['*' => Http::response(['message' => 'Conflicto de estado', 'code' => 'conflict'], 409)]);

    expect(fn () => app(PacProvider::class)->stampDraftInvoice('inv_409'))
        ->toThrow(PacConflictException::class);
});

test('la API key nunca aparece en el mensaje de una excepción lanzada desde stampDraftInvoice', function () {
    Http::fake(['*' => Http::response(['message' => 'No autorizado', 'code' => 'unauthorized'], 401)]);

    try {
        app(PacProvider::class)->stampDraftInvoice('inv_401');
        test()->fail('Se esperaba PacAuthenticationException');
    } catch (PacAuthenticationException $e) {
        expect($e->getMessage())->not->toContain('sk_test_STAMP_SERVICE');
    }
});

// ==================== PRECONDITIONS (locales, sin HTTP) ====================

test('sin pac_draft_external_id: falla localmente sin llamar al PAC', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    app(CurrentTenant::class)->set($company->id);

    Http::fake();

    expect(fn () => app(StampPacDraftInvoiceService::class)->stamp($invoice))
        ->toThrow(RuntimeException::class);

    Http::assertNothingSent();
});

test('ya tiene cfdi_uuid: lanza InvoiceAlreadyIssuedException sin llamar al PAC', function () {
    $company = Company::factory()->create();
    $invoice = draftReadyInvoiceForStampTest($company, [
        'cfdi_uuid' => 'EEEEEEEE-1111-2222-3333-444444444444',
        'pac_external_id' => 'inv_already_stamped',
        'pac_status' => 'valid',
    ]);
    app(CurrentTenant::class)->set($company->id);

    Http::fake();

    expect(fn () => app(StampPacDraftInvoiceService::class)->stamp($invoice))
        ->toThrow(InvoiceAlreadyIssuedException::class);

    Http::assertNothingSent();
});

test('ya tiene pac_external_id (aunque cfdi_uuid siga vacío): lanza InvoiceAlreadyIssuedException sin llamar al PAC', function () {
    $company = Company::factory()->create();
    $invoice = draftReadyInvoiceForStampTest($company, ['pac_external_id' => 'inv_reserved_elsewhere']);
    app(CurrentTenant::class)->set($company->id);

    Http::fake();

    expect(fn () => app(StampPacDraftInvoiceService::class)->stamp($invoice))
        ->toThrow(InvoiceAlreadyIssuedException::class);

    Http::assertNothingSent();
});

test('pac_issue_status=pending local: lanza InvoiceIssuanceInProgressException sin llamar al PAC (otra operación activa)', function () {
    $company = Company::factory()->create();
    $invoice = draftReadyInvoiceForStampTest($company, [
        'pac_issue_status' => 'pending',
        'pac_issue_started_at' => now(),
        'pac_issue_attempts' => 1,
    ]);
    app(CurrentTenant::class)->set($company->id);

    Http::fake();

    expect(fn () => app(StampPacDraftInvoiceService::class)->stamp($invoice))
        ->toThrow(InvoiceIssuanceInProgressException::class);

    Http::assertNothingSent();
});

test('multi-tenant: no se puede timbrar el borrador de una Invoice de otra empresa', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $foreignInvoice = draftReadyInvoiceForStampTest($companyB);

    app(CurrentTenant::class)->set($companyA->id);

    Http::fake();

    expect(fn () => app(StampPacDraftInvoiceService::class)->stamp($foreignInvoice))
        ->toThrow(ModelNotFoundException::class);

    Http::assertNothingSent();
});

// ==================== REMOTE SYNC (nunca confía en el valor local viejo) ====================

test('draft sincronizado con is_ready_to_stamp=false bloquea con InvoiceDraftNotReadyToStampException — nunca llama a /stamp', function () {
    $company = Company::factory()->create();
    $invoice = draftReadyInvoiceForStampTest($company); // local dice ready=true (desactualizado)
    app(CurrentTenant::class)->set($company->id);

    Http::fake(['*' => Http::response(fakeSyncedReadyDraftBody($invoice, ['is_ready_to_stamp' => false]), 200)]);

    expect(fn () => app(StampPacDraftInvoiceService::class)->stamp($invoice))
        ->toThrow(InvoiceDraftNotReadyToStampException::class);

    Http::assertSentCount(1); // solo el GET de sincronización
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/stamp'));

    $fresh = $invoice->fresh();
    expect($fresh->pac_draft_ready_to_stamp)->toBeFalse() // el sync SÍ reflejó el valor remoto real
        ->and($fresh->cfdi_uuid)->toBeNull()
        ->and($fresh->pac_issue_status)->toBeNull(); // nunca llegó a reservar
});

test('si el draft sincronizado revela que el PAC ya lo timbró (status=valid), reconcilia en vez de llamar a /stamp — nunca duplica la operación', function () {
    $company = Company::factory()->create();
    $invoice = draftReadyInvoiceForStampTest($company);
    app(CurrentTenant::class)->set($company->id);

    // Misma URL para el GET de sincronización y el GET de reconciliación
    // (retrieveInvoice sobre el mismo id) — un solo fake cubre ambas.
    Http::fake(['*' => Http::response([
        'id' => $invoice->pac_draft_external_id,
        'status' => 'valid',
        'livemode' => false,
        'uuid' => 'DDDDDDDD-1111-2222-3333-444444444444',
        'stamp' => ['date' => '2026-08-07T12:00:00Z'],
    ], 200)]);

    $result = app(StampPacDraftInvoiceService::class)->stamp($invoice);

    Http::assertSentCount(2); // sync (GET) + reconcile (GET) — nunca POST /stamp
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/stamp'));

    expect($result->cfdi_uuid)->toBe('DDDDDDDD-1111-2222-3333-444444444444')
        ->and($result->pac_external_id)->toBe($invoice->pac_draft_external_id)
        ->and($result->pac_issue_status)->toBe('succeeded');
});

test('si el draft sincronizado revela que el PAC ya lo tiene en pending, bloquea con InvoiceIssuanceInProgressException — nunca llama a /stamp', function () {
    $company = Company::factory()->create();
    $invoice = draftReadyInvoiceForStampTest($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake(['*' => Http::response([
        'id' => $invoice->pac_draft_external_id,
        'status' => 'pending',
        'livemode' => false,
    ], 200)]);

    expect(fn () => app(StampPacDraftInvoiceService::class)->stamp($invoice))
        ->toThrow(InvoiceIssuanceInProgressException::class);

    Http::assertSentCount(1); // solo el GET de sincronización
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/stamp'));
});

// ==================== SUCCESS ====================

test('timbrado exitoso: el borrador se transforma en la factura timbrada (pac_external_id == pac_draft_external_id)', function () {
    $company = Company::factory()->create();
    $invoice = draftReadyInvoiceForStampTest($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake([
        "*/invoices/{$invoice->pac_draft_external_id}" => Http::response(fakeSyncedReadyDraftBody($invoice), 200),
        "*/invoices/{$invoice->pac_draft_external_id}/stamp" => Http::response(fakeStampSuccessBody($invoice), 200),
    ]);

    $updated = app(StampPacDraftInvoiceService::class)->stamp($invoice);

    expect($updated->pac_external_id)->toBe($invoice->pac_draft_external_id)
        ->and($updated->cfdi_uuid)->toBe('BBBBBBBB-1111-2222-3333-444444444444')
        ->and($updated->pac_status)->toBe('valid')
        ->and($updated->stamped_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($updated->last_pac_sync_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($updated->pac_response)->toBeArray()
        ->and($updated->pac_last_error)->toBeNull()
        ->and($updated->pac_issue_status)->toBe('succeeded')
        ->and($updated->pac_issue_attempts)->toBe(1)
        ->and($updated->pac_reconciliation_required)->toBeFalse()
        ->and($updated->pac_provider)->toBe('facturapi')
        // trazabilidad: el rastro del draft nunca se borra
        ->and($updated->pac_draft_external_id)->toBe($invoice->pac_draft_external_id)
        ->and($updated->pac_draft_idempotency_key)->not->toBeNull()
        ->and($updated->pac_draft_status)->toBe('valid')
        ->and($updated->pacEvents()->pluck('event_type')->all())->toBe([
            InvoicePacEventType::DraftSynced,
            InvoicePacEventType::StampAttempted,
            InvoicePacEventType::StampSucceeded,
        ]);
});

test('un fallo de auditoría no repite stamp ni convierte un éxito remoto persistido en fallo', function () {
    $company = Company::factory()->create();
    $invoice = draftReadyInvoiceForStampTest($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake([
        "*/invoices/{$invoice->pac_draft_external_id}" => Http::response(fakeSyncedReadyDraftBody($invoice), 200),
        "*/invoices/{$invoice->pac_draft_external_id}/stamp" => Http::response(fakeStampSuccessBody($invoice), 200),
    ]);

    app()->instance(
        InvoicePacAuditService::class,
        new class extends InvoicePacAuditService
        {
            public function append(
                Invoice $invoice,
                InvoicePacEventType $type,
                array $context = [],
                ?string $pacCode = null,
            ): InvoicePacEvent {
                throw new RuntimeException('Fallo simulado de auditoría.');
            }
        },
    );

    $updated = app(StampPacDraftInvoiceService::class)->stamp($invoice);

    expect($updated->cfdi_uuid)->toBe('BBBBBBBB-1111-2222-3333-444444444444')
        ->and($updated->pac_issue_status)->toBe('succeeded')
        ->and($updated->pacEvents()->count())->toBe(0);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/stamp'));
});

test('nunca crea un CFDI nuevo: solo se envían un GET (sync) y un POST .../stamp, nunca un POST /invoices (createInvoice)', function () {
    $company = Company::factory()->create();
    $invoice = draftReadyInvoiceForStampTest($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake([
        "*/invoices/{$invoice->pac_draft_external_id}" => Http::response(fakeSyncedReadyDraftBody($invoice), 200),
        "*/invoices/{$invoice->pac_draft_external_id}/stamp" => Http::response(fakeStampSuccessBody($invoice), 200),
    ]);

    app(StampPacDraftInvoiceService::class)->stamp($invoice);

    Http::assertSentCount(2);
    Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/invoices'));
});

test('no envía async=true en el payload de /stamp', function () {
    $company = Company::factory()->create();
    $invoice = draftReadyInvoiceForStampTest($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake([
        "*/invoices/{$invoice->pac_draft_external_id}" => Http::response(fakeSyncedReadyDraftBody($invoice), 200),
        "*/invoices/{$invoice->pac_draft_external_id}/stamp" => Http::response(fakeStampSuccessBody($invoice), 200),
    ]);

    app(StampPacDraftInvoiceService::class)->stamp($invoice);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/stamp')
        && $request->method() === 'POST'
        && ! str_contains($request->body() ?? '', 'async'));
});

test('dispatch: InvoiceIssued se despacha una sola vez, únicamente después del commit', function () {
    Event::fake([InvoiceIssued::class]);

    $company = Company::factory()->create();
    $invoice = draftReadyInvoiceForStampTest($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake([
        "*/invoices/{$invoice->pac_draft_external_id}" => Http::response(fakeSyncedReadyDraftBody($invoice), 200),
        "*/invoices/{$invoice->pac_draft_external_id}/stamp" => Http::response(fakeStampSuccessBody($invoice), 200),
    ]);

    $updated = app(StampPacDraftInvoiceService::class)->stamp($invoice);

    Event::assertDispatchedTimes(InvoiceIssued::class, 1);
    Event::assertDispatched(InvoiceIssued::class, function (InvoiceIssued $event) use ($updated) {
        return $event->invoice->is($updated)
            && $event->result->externalId === $updated->pac_external_id
            && $event->invoice->cfdi_uuid === $updated->cfdi_uuid;
    });
});

// ==================== PENDING (defensivo, aunque nunca se envía async=true) ====================

test('si /stamp responde status=pending, NO se considera emitido: no dispara InvoiceIssued, conserva reconciliation_required', function () {
    Event::fake([InvoiceIssued::class]);

    $company = Company::factory()->create();
    $invoice = draftReadyInvoiceForStampTest($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake([
        "*/invoices/{$invoice->pac_draft_external_id}" => Http::response(fakeSyncedReadyDraftBody($invoice), 200),
        "*/invoices/{$invoice->pac_draft_external_id}/stamp" => Http::response([
            'id' => $invoice->pac_draft_external_id,
            'status' => 'pending',
            'livemode' => false,
        ], 200),
    ]);

    $updated = app(StampPacDraftInvoiceService::class)->stamp($invoice);

    expect($updated->cfdi_uuid)->toBeNull()
        ->and($updated->pac_status)->toBe('pending')
        ->and($updated->pac_issue_status)->toBe('pending')
        ->and($updated->pac_reconciliation_required)->toBeTrue();

    Event::assertNotDispatched(InvoiceIssued::class);
});

test('una segunda llamada tras un resultado pending no repite /stamp: bloquea porque el recurso ya quedó identificado', function () {
    $company = Company::factory()->create();
    $invoice = draftReadyInvoiceForStampTest($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake([
        "*/invoices/{$invoice->pac_draft_external_id}" => Http::response(fakeSyncedReadyDraftBody($invoice), 200),
        "*/invoices/{$invoice->pac_draft_external_id}/stamp" => Http::response([
            'id' => $invoice->pac_draft_external_id,
            'status' => 'pending',
            'livemode' => false,
        ], 200),
    ]);

    $afterFirstAttempt = app(StampPacDraftInvoiceService::class)->stamp($invoice);

    Http::fake(); // cualquier llamada a partir de aquí sería inesperada

    expect(fn () => app(StampPacDraftInvoiceService::class)->stamp($afterFirstAttempt))
        ->toThrow(InvoiceAlreadyIssuedException::class);

    Http::assertNothingSent();
});

// ==================== FAILURES ====================

test('error de validación (400/422) en /stamp es DEFINITIVO: pac_issue_status=failed, reconciliation_required=false, conserva el draft', function (int $status) {
    $company = Company::factory()->create();
    $invoice = draftReadyInvoiceForStampTest($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake([
        "*/invoices/{$invoice->pac_draft_external_id}" => Http::response(fakeSyncedReadyDraftBody($invoice), 200),
        "*/invoices/{$invoice->pac_draft_external_id}/stamp" => Http::response(['message' => 'Borrador incompleto', 'code' => 'invalid_draft'], $status),
    ]);

    expect(fn () => app(StampPacDraftInvoiceService::class)->stamp($invoice))
        ->toThrow(PacValidationException::class);

    $fresh = $invoice->fresh();
    expect($fresh->pac_issue_status)->toBe('failed')
        ->and($fresh->pac_reconciliation_required)->toBeFalse()
        ->and($fresh->pac_last_error)->toContain('invalid_draft')
        ->and($fresh->cfdi_uuid)->toBeNull()
        ->and($fresh->pac_draft_external_id)->toBe($invoice->pac_draft_external_id)
        ->and($fresh->pacEvents()->pluck('event_type')->all())->toBe([
            InvoicePacEventType::DraftSynced,
            InvoicePacEventType::StampAttempted,
            InvoicePacEventType::StampFailed,
        ]);
})->with([400, 422]);

test('error de autenticación (401/403) en /stamp es DEFINITIVO: pac_issue_status=failed', function (int $status) {
    $company = Company::factory()->create();
    $invoice = draftReadyInvoiceForStampTest($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake([
        "*/invoices/{$invoice->pac_draft_external_id}" => Http::response(fakeSyncedReadyDraftBody($invoice), 200),
        "*/invoices/{$invoice->pac_draft_external_id}/stamp" => Http::response(['message' => 'No autorizado', 'code' => 'unauthorized'], $status),
    ]);

    expect(fn () => app(StampPacDraftInvoiceService::class)->stamp($invoice))
        ->toThrow(PacAuthenticationException::class);

    expect($invoice->fresh()->pac_issue_status)->toBe('failed');
})->with([401, 403]);

test('rate limit (429) en /stamp es DEFINITIVO: pac_issue_status=failed', function () {
    $company = Company::factory()->create();
    $invoice = draftReadyInvoiceForStampTest($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake([
        "*/invoices/{$invoice->pac_draft_external_id}" => Http::response(fakeSyncedReadyDraftBody($invoice), 200),
        "*/invoices/{$invoice->pac_draft_external_id}/stamp" => Http::response(['message' => 'Demasiadas solicitudes', 'code' => 'rate_limited'], 429),
    ]);

    expect(fn () => app(StampPacDraftInvoiceService::class)->stamp($invoice))
        ->toThrow(PacRateLimitException::class);

    expect($invoice->fresh()->pac_issue_status)->toBe('failed');
});

test('HTTP 409 en /stamp es AMBIGUO (PacConflictException): reconciliation_required=true, nunca se trata como validación genérica', function () {
    $company = Company::factory()->create();
    $invoice = draftReadyInvoiceForStampTest($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake([
        "*/invoices/{$invoice->pac_draft_external_id}" => Http::response(fakeSyncedReadyDraftBody($invoice), 200),
        "*/invoices/{$invoice->pac_draft_external_id}/stamp" => Http::response(['message' => 'Conflicto de estado', 'code' => 'conflict'], 409),
    ]);

    expect(fn () => app(StampPacDraftInvoiceService::class)->stamp($invoice))
        ->toThrow(PacConflictException::class);

    $fresh = $invoice->fresh();
    expect($fresh->pac_issue_status)->toBe('reconciliation_required')
        ->and($fresh->pac_reconciliation_required)->toBeTrue()
        ->and($fresh->cfdi_uuid)->toBeNull();
});

test('5xx en /stamp es AMBIGUO: pac_issue_status=reconciliation_required', function () {
    $company = Company::factory()->create();
    $invoice = draftReadyInvoiceForStampTest($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake([
        "*/invoices/{$invoice->pac_draft_external_id}" => Http::response(fakeSyncedReadyDraftBody($invoice), 200),
        "*/invoices/{$invoice->pac_draft_external_id}/stamp" => Http::response(['message' => 'Error interno del PAC'], 500),
    ]);

    expect(fn () => app(StampPacDraftInvoiceService::class)->stamp($invoice))
        ->toThrow(PacUnavailableException::class);

    $fresh = $invoice->fresh();
    expect($fresh->pac_issue_status)->toBe('reconciliation_required')
        ->and($fresh->pac_reconciliation_required)->toBeTrue();
});

test('un timeout/conexión interrumpida en /stamp es AMBIGUO: pac_issue_status=reconciliation_required, nunca reintenta automáticamente', function () {
    $company = Company::factory()->create();
    $invoice = draftReadyInvoiceForStampTest($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake([
        "*/invoices/{$invoice->pac_draft_external_id}" => Http::response(fakeSyncedReadyDraftBody($invoice), 200),
        "*/invoices/{$invoice->pac_draft_external_id}/stamp" => function () {
            throw new ConnectionException('Connection timed out después de 5 segundos');
        },
    ]);

    expect(fn () => app(StampPacDraftInvoiceService::class)->stamp($invoice))
        ->toThrow(ConnectionException::class);

    $fresh = $invoice->fresh();
    expect($fresh->pac_issue_status)->toBe('reconciliation_required')
        ->and($fresh->pac_reconciliation_required)->toBeTrue()
        ->and($fresh->cfdi_uuid)->toBeNull();
});

test('una respuesta 200 no parseable/inesperada en /stamp es AMBIGUA: pac_issue_status=reconciliation_required', function () {
    $company = Company::factory()->create();
    $invoice = draftReadyInvoiceForStampTest($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake([
        "*/invoices/{$invoice->pac_draft_external_id}" => Http::response(fakeSyncedReadyDraftBody($invoice), 200),
        "*/invoices/{$invoice->pac_draft_external_id}/stamp" => Http::response(['foo' => 'bar'], 200),
    ]);

    expect(fn () => app(StampPacDraftInvoiceService::class)->stamp($invoice))
        ->toThrow(PacUnexpectedResponseException::class);

    expect($invoice->fresh()->pac_issue_status)->toBe('reconciliation_required');
});

test('si /stamp devuelve un id remoto distinto del borrador solicitado, se detiene con PacUnexpectedResponseException sin persistir', function () {
    $company = Company::factory()->create();
    $invoice = draftReadyInvoiceForStampTest($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake([
        "*/invoices/{$invoice->pac_draft_external_id}" => Http::response(fakeSyncedReadyDraftBody($invoice), 200),
        "*/invoices/{$invoice->pac_draft_external_id}/stamp" => Http::response(fakeStampSuccessBody($invoice, ['id' => 'inv_id_distinto_inesperado']), 200),
    ]);

    expect(fn () => app(StampPacDraftInvoiceService::class)->stamp($invoice))
        ->toThrow(PacUnexpectedResponseException::class);

    $fresh = $invoice->fresh();
    expect($fresh->cfdi_uuid)->toBeNull()
        ->and($fresh->pac_external_id)->toBeNull()
        ->and($fresh->pac_issue_status)->toBe('reconciliation_required')
        ->and($fresh->pac_reconciliation_required)->toBeTrue();
});

test('pac_last_error nunca contiene la API key, Authorization ni Bearer', function () {
    $company = Company::factory()->create();
    $invoice = draftReadyInvoiceForStampTest($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake([
        "*/invoices/{$invoice->pac_draft_external_id}" => Http::response(fakeSyncedReadyDraftBody($invoice), 200),
        "*/invoices/{$invoice->pac_draft_external_id}/stamp" => Http::response(['message' => 'Borrador incompleto', 'code' => 'invalid_draft'], 422),
    ]);

    try {
        app(StampPacDraftInvoiceService::class)->stamp($invoice);
    } catch (PacValidationException) {
        // esperado
    }

    $error = $invoice->fresh()->pac_last_error;

    expect($error)->toContain('invalid_draft')
        ->and($error)->not->toContain('sk_test_STAMP_SERVICE')
        ->and($error)->not->toContain('Bearer')
        ->and($error)->not->toContain('Authorization');
});

// ==================== IDEMPOTENCY / CONCURRENCY ====================

test('la reserva marca pending, fija pac_issue_started_at e incrementa pac_issue_attempts ANTES de llamar a /stamp', function () {
    $company = Company::factory()->create();
    $invoice = draftReadyInvoiceForStampTest($company);
    app(CurrentTenant::class)->set($company->id);

    $pendingStateDuringStampCall = null;
    $attemptsDuringStampCall = null;

    Http::fake([
        "*/invoices/{$invoice->pac_draft_external_id}" => Http::response(fakeSyncedReadyDraftBody($invoice), 200),
        "*/invoices/{$invoice->pac_draft_external_id}/stamp" => function () use ($invoice, &$pendingStateDuringStampCall, &$attemptsDuringStampCall) {
            $fresh = Invoice::withoutGlobalScope(CompanyScope::class)->find($invoice->id);
            $pendingStateDuringStampCall = $fresh->pac_issue_status;
            $attemptsDuringStampCall = $fresh->pac_issue_attempts;

            return Http::response(fakeStampSuccessBody($invoice), 200);
        },
    ]);

    app(StampPacDraftInvoiceService::class)->stamp($invoice);

    expect($pendingStateDuringStampCall)->toBe('pending')
        ->and($attemptsDuringStampCall)->toBe(1);
});

test('el lock de la reserva se libera antes de la llamada HTTP: la reserva ya está comprometida (commit) cuando el PAC responde', function () {
    $company = Company::factory()->create();
    $invoice = draftReadyInvoiceForStampTest($company);
    app(CurrentTenant::class)->set($company->id);

    $baselineTransactionLevel = DB::transactionLevel();
    $transactionLevelDuringCall = null;

    Http::fake([
        "*/invoices/{$invoice->pac_draft_external_id}" => Http::response(fakeSyncedReadyDraftBody($invoice), 200),
        "*/invoices/{$invoice->pac_draft_external_id}/stamp" => function () use (&$transactionLevelDuringCall, $invoice) {
            $transactionLevelDuringCall = DB::transactionLevel();

            return Http::response(fakeStampSuccessBody($invoice), 200);
        },
    ]);

    app(StampPacDraftInvoiceService::class)->stamp($invoice);

    expect($transactionLevelDuringCall)->toBe($baselineTransactionLevel);
});

test('una respuesta atrasada de /stamp nunca sobrescribe una emisión ya finalizada por otra ejecución concurrente', function () {
    Event::fake([InvoiceIssued::class]);

    $company = Company::factory()->create();
    $invoice = draftReadyInvoiceForStampTest($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake([
        "*/invoices/{$invoice->pac_draft_external_id}" => Http::response(fakeSyncedReadyDraftBody($invoice), 200),
        "*/invoices/{$invoice->pac_draft_external_id}/stamp" => function () use ($invoice) {
            // Simula que otra ejecución (ej. una reconciliación concurrente)
            // ya persistió el CFDI justo después de llamar al PAC pero
            // antes de nuestra transacción de persistencia final.
            Invoice::withoutGlobalScope(CompanyScope::class)->whereKey($invoice->id)->update([
                'pac_external_id' => $invoice->pac_draft_external_id,
                'cfdi_uuid' => 'CCCCCCCC-1111-2222-3333-444444444444',
                'pac_status' => 'valid',
                'pac_issue_status' => 'succeeded',
                'pac_reconciliation_required' => false,
            ]);

            return Http::response(fakeStampSuccessBody($invoice), 200);
        },
    ]);

    $result = app(StampPacDraftInvoiceService::class)->stamp($invoice);

    expect($result->cfdi_uuid)->toBe('CCCCCCCC-1111-2222-3333-444444444444');

    // Nuestra propia llamada "perdedora" nunca vuelve a disparar el evento
    // — ya se resolvió por la otra vía antes de nuestro commit.
    Event::assertNotDispatched(InvoiceIssued::class);
});
