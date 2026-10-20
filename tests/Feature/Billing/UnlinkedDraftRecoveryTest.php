<?php

use App\Enums\InvoiceHealthAction;
use App\Enums\InvoiceHealthStatus;
use App\Enums\InvoicePacEventType;
use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Exceptions\Billing\PacUnexpectedEnvironmentException;
use App\Exceptions\Billing\PacUnexpectedResponseException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Billing\InvoiceBusinessCapabilitiesService;
use App\Services\Billing\InvoiceHealthEvaluator;
use App\Services\Billing\ReconcileInvoiceWithPacService;
use App\Support\Billing\PacIdentifiers;
use App\Support\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Fase 6.20: la llave del borrador quedó reservada pero nunca se guardó
 * pac_draft_external_id. Verificado en Facturapi TEST: repetir el POST con
 * la misma llave responde siempre 409 idempotency_key_in_use (nunca
 * devuelve el borrador original), y GET /invoices?external_id= sí incluye
 * borradores con su idempotency_key. La reconciliación lo busca por
 * draftExternalId y sólo lo vincula si la llave coincide.
 */
beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_PHASE_620_DRAFT',
    ]);

    Http::preventStrayRequests();
});

/** @return array{Invoice, User} */
function phase620UnlinkedDraftInvoice(array $overrides = []): array
{
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Admin]);
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Issued,
        'subtotal' => 100,
        'tax_total' => 0,
        'total' => 100,
    ]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id, 'subtotal' => 100, 'total' => 100]);
    $invoice->forceFill(array_merge([
        'pac_provider' => 'facturapi',
        'pac_draft_idempotency_key' => PacIdentifiers::draftIdempotencyKey($company->id, $invoice->id),
    ], $overrides))->save();
    app(CurrentTenant::class)->set($company->id);

    return [$invoice->fresh(), $user];
}

/**
 * Simula Facturapi: listado por external_id y lectura del borrador.
 *
 * @param  array<string, mixed>  $draftOverrides
 */
function phase620FakeRemoteDraft(Invoice $invoice, ?array $draftOverrides = [], bool $listed = true): string
{
    $draftId = 'inv_orphan_'.$invoice->id;
    $draft = $draftOverrides === null ? null : array_merge([
        'id' => $draftId,
        'status' => 'draft',
        'livemode' => false,
        'is_ready_to_stamp' => true,
        'external_id' => PacIdentifiers::draftExternalId($invoice->company_id, $invoice->id),
        'idempotency_key' => $invoice->pac_draft_idempotency_key,
        'created_at' => '2026-10-20T19:52:47.123Z',
        'total' => 100,
    ], $draftOverrides);

    Http::fake(function (Request $request) use ($draftId, $draft, $listed) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if ($request->method() === 'GET' && $path === '/v2/invoices') {
            $data = $listed && $draft !== null ? [$draft] : [];

            return Http::response(['page' => 1, 'total_pages' => 1, 'total_results' => count($data), 'data' => $data]);
        }

        if ($request->method() === 'GET' && $path === "/v2/invoices/{$draftId}") {
            return Http::response($draft);
        }

        // Sin coincidencia: cede a otros fakes; preventStrayRequests()
        // sigue bloqueando cualquier petición no simulada.
        return null;
    });

    return $draftId;
}

test('el estado reservado sin id remoto habilita can_reconcile y aparece en billing:health como recuperable', function () {
    [$invoice, $user] = phase620UnlinkedDraftInvoice();

    $report = app(InvoiceHealthEvaluator::class)->evaluate($invoice, CarbonImmutable::now());
    $finding = $report->find('draft_reserved_without_remote_id');

    expect(app(InvoiceBusinessCapabilitiesService::class)->for($invoice, $user)['can_reconcile'])->toBeTrue()
        ->and($finding)->not->toBeNull()
        ->and($finding->status)->toBe(InvoiceHealthStatus::Recoverable)
        ->and($finding->action)->toBe(InvoiceHealthAction::Reconcile)
        // Reintentar la emisión sólo repetiría idempotency_key_in_use.
        ->and($report->codes())->not->toContain('erp_issued_without_cfdi');
});

test('el hallazgo no aplica con el borrador vinculado, sin llave reservada, ni fuera de ERP issued', function (array $attributes) {
    [$invoice] = phase620UnlinkedDraftInvoice($attributes);

    expect(app(InvoiceHealthEvaluator::class)->evaluate($invoice, CarbonImmutable::now())->codes())
        ->not->toContain('draft_reserved_without_remote_id');
})->with([
    'borrador vinculado' => [['pac_draft_external_id' => 'inv_linked', 'pac_draft_status' => 'draft']],
    'sin llave reservada' => [['pac_draft_idempotency_key' => null]],
    'ERP ready' => [['status' => InvoiceStatus::Ready]],
    'emisión con estado local' => [['pac_issue_status' => 'failed']],
]);

test('reconciliar busca por draftExternalId y vincula el borrador cuando la llave coincide, sin tocar la identidad fiscal', function () {
    [$invoice] = phase620UnlinkedDraftInvoice(['pac_reconciliation_required' => true]);
    $draftId = phase620FakeRemoteDraft($invoice);

    $result = app(ReconcileInvoiceWithPacService::class)->reconcile($invoice);

    $expectedExternalId = PacIdentifiers::draftExternalId($invoice->company_id, $invoice->id);
    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && ($request->data()['external_id'] ?? null) === $expectedExternalId);
    Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');

    expect($result->pac_draft_external_id)->toBe($draftId)
        ->and($result->pac_draft_status)->toBe('draft')
        ->and($result->pac_draft_ready_to_stamp)->toBeTrue()
        ->and($result->pac_draft_created_at->toIso8601String())->toBe('2026-10-20T19:52:47+00:00')
        ->and($result->pac_draft_response)->toBeArray()
        ->and($result->pac_reconciliation_required)->toBeFalse()
        ->and($result->pac_external_id)->toBeNull()
        ->and($result->cfdi_uuid)->toBeNull()
        ->and($result->pac_issue_status)->toBeNull()
        ->and($result->pac_status)->toBeNull()
        ->and($result->pacEvents()->pluck('event_type')->all())->toBe([InvoicePacEventType::Reconciled]);
});

test('no vincula si la idempotency_key o el external_id remotos no coinciden con la reserva', function (array $draftOverrides) {
    [$invoice] = phase620UnlinkedDraftInvoice();
    phase620FakeRemoteDraft($invoice, $draftOverrides);

    expect(fn () => app(ReconcileInvoiceWithPacService::class)->reconcile($invoice))
        ->toThrow(PacUnexpectedResponseException::class);

    $fresh = $invoice->fresh();
    expect($fresh->pac_draft_external_id)->toBeNull()
        ->and($fresh->pac_reconciliation_required)->toBeTrue();
})->with([
    'otra llave' => [['idempotency_key' => 'erp-invoice-draft:999:999:v1']],
    'sin llave' => [['idempotency_key' => null]],
    'otro external_id' => [['external_id' => 'company-999-invoice-999-draft']],
]);

test('livemode=true en el borrador encontrado bloquea sin vincular', function () {
    [$invoice] = phase620UnlinkedDraftInvoice();
    phase620FakeRemoteDraft($invoice, ['livemode' => true]);

    expect(fn () => app(ReconcileInvoiceWithPacService::class)->reconcile($invoice))
        ->toThrow(PacUnexpectedEnvironmentException::class);

    expect($invoice->fresh()->pac_draft_external_id)->toBeNull();
});

test('sin borrador remoto no vincula nada y libera la revisión para reintentar con la misma llave', function () {
    [$invoice] = phase620UnlinkedDraftInvoice([
        'pac_reconciliation_required' => true,
        'pac_last_error' => 'error previo',
    ]);
    phase620FakeRemoteDraft($invoice, listed: false);

    $result = app(ReconcileInvoiceWithPacService::class)->reconcile($invoice);

    $event = $result->pacEvents()->get()->last();
    expect($result->pac_draft_external_id)->toBeNull()
        ->and($result->pac_draft_idempotency_key)->toBe($invoice->pac_draft_idempotency_key)
        ->and($result->pac_reconciliation_required)->toBeFalse()
        ->and($result->pac_last_error)->toBeNull()
        ->and($event->event_type)->toBe(InvoicePacEventType::ReconciliationRequired)
        ->and($event->context['reason'])->toBe('remote_draft_not_found');
});

test('una factura con llave de emisión legacy conserva la búsqueda por externalId final', function () {
    [$invoice] = phase620UnlinkedDraftInvoice();
    $invoice->forceFill([
        'pac_idempotency_key' => PacIdentifiers::idempotencyKey($invoice->company_id, $invoice->id),
        'pac_issue_status' => 'reconciliation_required',
        'pac_reconciliation_required' => true,
    ])->save();
    Http::fake(['*' => Http::response(['page' => 1, 'total_pages' => 1, 'total_results' => 0, 'data' => []])]);

    app(ReconcileInvoiceWithPacService::class)->reconcile($invoice->fresh());

    Http::assertSent(fn (Request $request) => ($request->data()['external_id'] ?? null)
        === PacIdentifiers::externalId($invoice->company_id, $invoice->id));
});

test('tras la recuperación, operations/issue continúa desde el borrador vinculado sin crear otro', function () {
    [$invoice, $user] = phase620UnlinkedDraftInvoice();
    phase620FakeRemoteDraft($invoice);
    $linked = app(ReconcileInvoiceWithPacService::class)->reconcile($invoice);
    $draftId = $linked->pac_draft_external_id;

    Http::fake(function (Request $request) use ($draftId) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if ($request->method() === 'GET' && $path === "/v2/invoices/{$draftId}") {
            return Http::response(['id' => $draftId, 'status' => 'draft', 'livemode' => false, 'is_ready_to_stamp' => true]);
        }

        if ($request->method() === 'POST' && $path === "/v2/invoices/{$draftId}/stamp") {
            return Http::response([
                'id' => $draftId,
                'status' => 'valid',
                'livemode' => false,
                'uuid' => 'AAAAAAAA-1111-4222-8333-444444444444',
                'stamp' => ['date' => '2026-10-20T13:52:54'],
            ]);
        }

        return Http::response([], 599);
    });

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/operations/issue", ['confirm' => true])
        ->assertOk();

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST'
        && parse_url($request->url(), PHP_URL_PATH) === '/v2/invoices');

    $fresh = $invoice->fresh();
    expect($fresh->pac_external_id)->toBe($draftId)
        ->and($fresh->pac_status)->toBe('valid')
        ->and($fresh->stamped_at->toIso8601String())->toBe('2026-10-20T19:52:54+00:00');
});
