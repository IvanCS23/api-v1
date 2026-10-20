<?php

use App\Enums\InvoiceHealthAction;
use App\Enums\InvoiceHealthStatus;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\Billing\InvoiceHealthEvaluator;
use App\Support\Billing\PacIdentifiers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'billing.health.issue_pending_minutes' => 60,
        'billing.health.cancellation_pending_hours' => 96,
        'billing.health.draft_date_warning_hours' => 48,
        'billing.health.draft_date_limit_hours' => 72,
        'billing.cancel_in_flight_minutes' => 5,
    ]);
});

function healthNow(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-10-19 12:00:00');
}

/** Invoice en memoria (sin persistir): el evaluador es puro. */
function healthInvoice(array $attributes = []): Invoice
{
    return (new Invoice)->forceFill(array_merge([
        'id' => 501,
        'company_id' => 7,
        'folio' => 'FAC-00000501',
        'status' => InvoiceStatus::Draft,
        'pac_external_id' => null,
        'cfdi_uuid' => null,
        'pac_status' => null,
        'cancellation_status' => null,
        'last_pac_sync_at' => null,
        'pac_idempotency_key' => null,
        'pac_issue_status' => null,
        'pac_issue_started_at' => null,
        'pac_reconciliation_required' => false,
        'pac_draft_external_id' => null,
        'pac_draft_status' => null,
        'pac_draft_idempotency_key' => null,
        'pac_draft_created_at' => null,
        'cfdi_artifacts_status' => null,
        'cancellation_receipt_status' => null,
    ], $attributes));
}

/** Emisión completa y saludable según isSuccessfulIssue() + artifacts. */
function healthyIssuedAttributes(array $overrides = []): array
{
    return array_merge([
        'status' => InvoiceStatus::Issued,
        'pac_external_id' => 'inv_health_501',
        'cfdi_uuid' => '96013e83-154b-4153-8e61-c38b8966e560',
        'pac_status' => 'valid',
        'pac_issue_status' => 'succeeded',
        'pac_issue_started_at' => healthNow()->subDays(3),
        'pac_draft_external_id' => 'inv_health_501',
        'pac_draft_status' => 'valid',
        'pac_draft_idempotency_key' => PacIdentifiers::draftIdempotencyKey(7, 501),
        'pac_draft_created_at' => healthNow()->subDays(3),
        'last_pac_sync_at' => healthNow()->subDays(3),
        'cfdi_artifacts_status' => 'stored',
    ], $overrides);
}

function evaluateHealth(Invoice $invoice, ?CarbonImmutable $requestedAt = null)
{
    return app(InvoiceHealthEvaluator::class)->evaluate($invoice, healthNow(), $requestedAt);
}

test('combinaciones coherentes se clasifican como valid sin hallazgos', function (array $attributes) {
    $report = evaluateHealth(healthInvoice($attributes));

    expect($report->status)->toBe(InvoiceHealthStatus::Valid)
        ->and($report->findings)->toBe([]);
})->with([
    'borrador sin actividad PAC' => [['status' => InvoiceStatus::Draft]],
    'ready sin actividad PAC' => [['status' => InvoiceStatus::Ready]],
    'emitida con CFDI valido y artifacts' => [healthyIssuedAttributes()],
    'cancelada ERP-only sin identidad' => [['status' => InvoiceStatus::Cancelled]],
    'cancelada convergida con acuse' => [healthyIssuedAttributes([
        'status' => InvoiceStatus::Cancelled,
        'pac_status' => 'canceled',
        'cancellation_status' => 'accepted',
        'cancellation_receipt_status' => 'stored',
    ])],
    'cancelacion rechazada con CFDI vigente y ERP emitido' => [healthyIssuedAttributes([
        'cancellation_status' => 'rejected',
    ])],
]);

test('cada combinacion detectable produce su codigo, nivel y accion', function (
    array $attributes,
    string $code,
    InvoiceHealthStatus $level,
    InvoiceHealthAction $action,
) {
    $report = evaluateHealth(healthInvoice($attributes));
    $finding = $report->find($code);

    expect($finding)->not->toBeNull()
        ->and($finding->status)->toBe($level)
        ->and($finding->action)->toBe($action)
        ->and($report->status->isAtLeast($level))->toBeTrue();
})->with([
    'emision pendiente reciente' => [
        ['status' => InvoiceStatus::Issued, 'pac_issue_status' => 'pending', 'pac_issue_started_at' => healthNow()->subMinutes(59)],
        'issue_pending_recent', InvoiceHealthStatus::Transient, InvoiceHealthAction::Wait,
    ],
    'emision pendiente vencida' => [
        ['status' => InvoiceStatus::Issued, 'pac_issue_status' => 'pending', 'pac_issue_started_at' => healthNow()->subMinutes(60)],
        'issue_pending_stale', InvoiceHealthStatus::Recoverable, InvoiceHealthAction::Reconcile,
    ],
    'emision pendiente sin inicio registrado' => [
        ['status' => InvoiceStatus::Issued, 'pac_issue_status' => 'pending'],
        'issue_pending_stale', InvoiceHealthStatus::Recoverable, InvoiceHealthAction::Reconcile,
    ],
    'reconciliacion requerida por resultado ambiguo' => [
        ['status' => InvoiceStatus::Issued, 'pac_issue_status' => 'reconciliation_required', 'pac_reconciliation_required' => true],
        'reconciliation_required', InvoiceHealthStatus::Recoverable, InvoiceHealthAction::Reconcile,
    ],
    'emision fallida definitiva' => [
        ['status' => InvoiceStatus::Issued, 'pac_issue_status' => 'failed'],
        'issue_failed', InvoiceHealthStatus::Recoverable, InvoiceHealthAction::OperationsIssue,
    ],
    'ERP emitido sin CFDI (legacy)' => [
        ['status' => InvoiceStatus::Issued],
        'erp_issued_without_cfdi', InvoiceHealthStatus::Recoverable, InvoiceHealthAction::OperationsIssue,
    ],
    'CFDI cancelado sin convergencia ERP' => [
        healthyIssuedAttributes(['pac_status' => 'canceled', 'cancellation_status' => 'accepted']),
        'cfdi_canceled_erp_not_converged', InvoiceHealthStatus::Recoverable, InvoiceHealthAction::OperationsCancel,
    ],
    'ERP cancelado con CFDI vigente tras rechazo' => [
        healthyIssuedAttributes(['status' => InvoiceStatus::Cancelled, 'cancellation_status' => 'rejected']),
        'erp_cancelled_cfdi_valid', InvoiceHealthStatus::Recoverable, InvoiceHealthAction::ManualReview,
    ],
    'ERP cancelado con CFDI vigente tras expiracion' => [
        healthyIssuedAttributes(['status' => InvoiceStatus::Cancelled, 'cancellation_status' => 'expired']),
        'erp_cancelled_cfdi_valid', InvoiceHealthStatus::Recoverable, InvoiceHealthAction::ManualReview,
    ],
    'solicitud de cancelacion en vuelo reciente' => [
        healthyIssuedAttributes(['pac_cancel_request_status' => 'in_flight', 'pac_cancel_started_at' => healthNow()->subMinutes(4)]),
        'cancellation_request_in_flight', InvoiceHealthStatus::Transient, InvoiceHealthAction::Wait,
    ],
    'solicitud de cancelacion en vuelo abandonada' => [
        healthyIssuedAttributes(['pac_cancel_request_status' => 'in_flight', 'pac_cancel_started_at' => healthNow()->subMinutes(5)]),
        'cancellation_request_in_flight_stale', InvoiceHealthStatus::Recoverable, InvoiceHealthAction::Reconcile,
    ],
    'solicitud de cancelacion ambigua' => [
        healthyIssuedAttributes(['pac_cancel_request_status' => 'reconciliation_required', 'pac_reconciliation_required' => true]),
        'reconciliation_required', InvoiceHealthStatus::Recoverable, InvoiceHealthAction::Reconcile,
    ],
    'cancelacion aceptada sin pac_status canceled' => [
        healthyIssuedAttributes(['cancellation_status' => 'accepted']),
        'cancellation_accepted_not_canceled', InvoiceHealthStatus::Recoverable, InvoiceHealthAction::Reconcile,
    ],
    'identidad incompleta sin UUID' => [
        ['status' => InvoiceStatus::Issued, 'pac_external_id' => 'inv_health_501'],
        'identity_incomplete', InvoiceHealthStatus::Recoverable, InvoiceHealthAction::Reconcile,
    ],
    'borrador remoto timbrado sin enlazar' => [
        ['status' => InvoiceStatus::Issued, 'pac_draft_external_id' => 'inv_health_501', 'pac_draft_status' => 'valid'],
        'remote_draft_valid_unlinked', InvoiceHealthStatus::Recoverable, InvoiceHealthAction::Reconcile,
    ],
    'CFDI valido sin artifacts (informativo)' => [
        healthyIssuedAttributes(['cfdi_artifacts_status' => null]),
        'cfdi_artifacts_missing', InvoiceHealthStatus::Valid, InvoiceHealthAction::FetchArtifacts,
    ],
    'borrador remoto cerca de la ventana de 72 h' => [
        ['status' => InvoiceStatus::Issued, 'pac_draft_external_id' => 'inv_health_501', 'pac_draft_status' => 'draft', 'pac_draft_created_at' => healthNow()->subHours(48)],
        'remote_draft_date_window_risk', InvoiceHealthStatus::Transient, InvoiceHealthAction::OperationsIssue,
    ],
    'borrador remoto fuera de la ventana de 72 h' => [
        ['status' => InvoiceStatus::Issued, 'pac_draft_external_id' => 'inv_health_501', 'pac_draft_status' => 'draft', 'pac_draft_created_at' => healthNow()->subHours(72)],
        'remote_draft_date_window_risk', InvoiceHealthStatus::Recoverable, InvoiceHealthAction::ManualReview,
    ],
    'UUID sin identificador externo' => [
        ['status' => InvoiceStatus::Issued, 'cfdi_uuid' => '96013e83-154b-4153-8e61-c38b8966e560'],
        'identity_uuid_without_external_id', InvoiceHealthStatus::Invalid, InvoiceHealthAction::ManualReview,
    ],
    'ERP ready con CFDI timbrado' => [
        healthyIssuedAttributes(['status' => InvoiceStatus::Ready]),
        'erp_behind_cfdi', InvoiceHealthStatus::Invalid, InvoiceHealthAction::ManualReview,
    ],
    'emision exitosa sin identidad' => [
        ['status' => InvoiceStatus::Issued, 'pac_issue_status' => 'succeeded'],
        'issue_succeeded_without_valid_cfdi', InvoiceHealthStatus::Invalid, InvoiceHealthAction::ManualReview,
    ],
    'pac_status valido sin identidad' => [
        ['status' => InvoiceStatus::Issued, 'pac_status' => 'valid'],
        'pac_status_without_identity', InvoiceHealthStatus::Invalid, InvoiceHealthAction::ManualReview,
    ],
    'acuse almacenado sin solicitud de cancelacion (informativo)' => [
        healthyIssuedAttributes(['cancellation_receipt_status' => 'stored']),
        'receipt_without_cancellation', InvoiceHealthStatus::Valid, InvoiceHealthAction::ManualReview,
    ],
    'llave de idempotencia ajena' => [
        healthyIssuedAttributes(['pac_draft_idempotency_key' => PacIdentifiers::draftIdempotencyKey(999, 501)]),
        'idempotency_key_mismatch', InvoiceHealthStatus::Invalid, InvoiceHealthAction::ManualReview,
    ],
]);

test('borrador remoto reciente no reporta riesgo de ventana', function () {
    $report = evaluateHealth(healthInvoice([
        'status' => InvoiceStatus::Issued,
        'pac_draft_external_id' => 'inv_health_501',
        'pac_draft_status' => 'draft',
        'pac_draft_created_at' => healthNow()->subHours(47),
    ]));

    expect($report->find('remote_draft_date_window_risk'))->toBeNull();
});

test('emision pendiente reciente es solo transitoria aunque el flag de revision este activo', function () {
    $report = evaluateHealth(healthInvoice([
        'status' => InvoiceStatus::Issued,
        'pac_external_id' => 'inv_health_501',
        'pac_status' => 'pending',
        'pac_issue_status' => 'pending',
        'pac_issue_started_at' => healthNow()->subMinutes(10),
        'pac_reconciliation_required' => true,
    ]));

    expect($report->status)->toBe(InvoiceHealthStatus::Transient)
        ->and($report->codes())->toBe(['issue_pending_recent']);
});

test('cancelacion pendiente usa el evento CancellationRequested y respeta el umbral', function () {
    $pending = healthInvoice(healthyIssuedAttributes([
        'cancellation_status' => 'pending',
        'pac_reconciliation_required' => true,
        'last_pac_sync_at' => healthNow()->subHours(200),
    ]));

    $recent = evaluateHealth($pending, healthNow()->subHours(95));
    $stale = evaluateHealth($pending, healthNow()->subHours(96));

    expect($recent->status)->toBe(InvoiceHealthStatus::Transient)
        ->and($recent->codes())->toBe(['cancellation_pending_recent'])
        ->and($recent->findings[0]->ageMinutes)->toBe(95 * 60)
        ->and($stale->status)->toBe(InvoiceHealthStatus::Recoverable)
        ->and($stale->codes())->toBe(['cancellation_pending_stale']);
});

test('cancelacion pendiente sin evento usa last_pac_sync_at como respaldo', function () {
    $report = evaluateHealth(healthInvoice(healthyIssuedAttributes([
        'cancellation_status' => 'verifying',
        'pac_reconciliation_required' => true,
        'last_pac_sync_at' => healthNow()->subHours(2),
    ])));

    expect($report->codes())->toBe(['cancellation_pending_recent']);
});

test('reconciliacion explicita no se oculta aunque haya una operacion pendiente', function () {
    $report = evaluateHealth(healthInvoice([
        'status' => InvoiceStatus::Issued,
        'pac_status' => 'pending',
        'pac_external_id' => 'inv_health_501',
        'pac_issue_status' => 'reconciliation_required',
        'pac_reconciliation_required' => true,
        'pac_issue_started_at' => healthNow()->subMinutes(5),
    ]));

    expect($report->codes())->toContain('issue_pending_recent', 'reconciliation_required')
        ->and($report->status)->toBe(InvoiceHealthStatus::Recoverable);
});

test('el estado global es el peor hallazgo', function () {
    $report = evaluateHealth(healthInvoice(healthyIssuedAttributes([
        'cancellation_status' => 'pending',
        'pac_reconciliation_required' => true,
        'pac_draft_idempotency_key' => PacIdentifiers::draftIdempotencyKey(999, 501),
    ])), healthNow()->subHour());

    expect($report->codes())->toContain('cancellation_pending_recent', 'idempotency_key_mismatch')
        ->and($report->status)->toBe(InvoiceHealthStatus::Invalid);
});

test('acuse con cancelacion pendiente dentro del plazo y ERP emitido es solo transitorio', function () {
    // Facturapi responde status=valid + cancellation_status=pending mientras
    // el receptor debe aceptar; el acuse no cambia la clasificación.
    $report = evaluateHealth(healthInvoice(healthyIssuedAttributes([
        'cancellation_status' => 'pending',
        'pac_reconciliation_required' => true,
        'cancellation_receipt_status' => 'stored',
    ])), healthNow()->subHours(24));

    expect($report->status)->toBe(InvoiceHealthStatus::Transient)
        ->and($report->codes())->toBe(['cancellation_pending_recent']);
});

test('ERP cancelado con CFDI vigente y cancelacion en curso viola el invariante', function (string $cancellation) {
    $report = evaluateHealth(healthInvoice(healthyIssuedAttributes([
        'status' => InvoiceStatus::Cancelled,
        'cancellation_status' => $cancellation,
        'pac_reconciliation_required' => true,
        'cancellation_receipt_status' => 'stored',
    ])), healthNow()->subHours(24));
    $finding = $report->find('erp_cancelled_before_pac_confirmation');

    expect($report->status)->toBe(InvoiceHealthStatus::Invalid)
        ->and($finding?->status)->toBe(InvoiceHealthStatus::Invalid)
        ->and($finding?->action)->toBe(InvoiceHealthAction::ManualReview)
        ->and($report->find('erp_cancelled_cfdi_valid'))->toBeNull();
})->with(['pending', 'verifying']);

test('acuse con cancelacion rechazada o expirada y ERP emitido: CFDI vigente, sin hallazgos', function (string $cancellation) {
    $report = evaluateHealth(healthInvoice(healthyIssuedAttributes([
        'cancellation_status' => $cancellation,
        'cancellation_receipt_status' => 'stored',
    ])));

    expect($report->status)->toBe(InvoiceHealthStatus::Valid)
        ->and($report->findings)->toBe([]);
})->with(['rejected', 'expired']);

test('ERP cancelado con CFDI vigente sin cancelacion en curso es recuperable aunque exista acuse', function (?string $cancellation) {
    $report = evaluateHealth(healthInvoice(healthyIssuedAttributes([
        'status' => InvoiceStatus::Cancelled,
        'cancellation_status' => $cancellation,
        'cancellation_receipt_status' => 'stored',
    ])));

    expect($report->status)->toBe(InvoiceHealthStatus::Recoverable)
        ->and($report->find('erp_cancelled_cfdi_valid')?->status)->toBe(InvoiceHealthStatus::Recoverable);
})->with(['rejected', 'expired', null]);

test('hallazgos informativos no elevan el estado global', function () {
    $report = evaluateHealth(healthInvoice(healthyIssuedAttributes([
        'cfdi_artifacts_status' => null,
        'cancellation_receipt_status' => 'stored',
    ])));

    expect($report->status)->toBe(InvoiceHealthStatus::Valid)
        ->and($report->codes())->toBe(['receipt_without_cancellation', 'cfdi_artifacts_missing'])
        ->and(collect($report->findings)->every->isInformational())->toBeTrue();
});

test('los umbrales son configurables', function () {
    config(['billing.health.issue_pending_minutes' => 5]);

    $report = evaluateHealth(healthInvoice([
        'status' => InvoiceStatus::Issued,
        'pac_issue_status' => 'pending',
        'pac_issue_started_at' => healthNow()->subMinutes(6),
    ]));

    expect($report->codes())->toBe(['issue_pending_stale'])
        ->and($report->findings[0]->ageMinutes)->toBe(6);
});

test('el evaluador no persiste, no consulta la BD y no hace HTTP', function () {
    Http::fake();
    $invoice = healthInvoice(healthyIssuedAttributes(['cfdi_artifacts_status' => null]));
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    evaluateHealth($invoice);

    expect($queries)->toBe(0)
        ->and($invoice->exists)->toBeFalse();
    Http::assertNothingSent();
});
