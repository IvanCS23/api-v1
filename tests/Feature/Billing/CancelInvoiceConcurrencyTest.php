<?php

use App\Enums\CfdiCancellationMotive;
use App\Enums\InvoicePacEventType;
use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Exceptions\Billing\PacReconciliationRequiredException;
use App\Exceptions\Billing\PacValidationException;
use App\Exceptions\InvoiceCancellationInProgressException;
use App\Exceptions\InvoiceCannotBeCancelledException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\CancelInvoiceWithPacService;
use App\Services\Billing\InvoiceBusinessCapabilitiesService;
use App\Services\Billing\InvoiceWorkflow;
use App\Services\Billing\IssueInvoiceService;
use App\Services\Billing\ReconcileInvoiceWithPacService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Fase 6.19: reserva de la solicitud de cancelación bajo lock. SQLite en
 * memoria no permite procesos paralelos reales; la concurrencia se simula
 * con llamadas reentrantes dentro del fake HTTP (mismo enfoque que
 * IssueInvoiceConcurrencyTest).
 */
beforeEach(function () {
    facturapiOrganizationsFromConfigKey();
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_PHASE_619_CANCEL',
        'billing.cancel_in_flight_minutes' => 5,
    ]);
    Http::preventStrayRequests();
});

function phase619CancelableInvoice(array $attributes = []): Invoice
{
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    $invoice->forceFill(array_merge([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_phase619_cancel_'.$invoice->id,
        'cfdi_uuid' => 'fced601d-c4f6-4ce7-8f05-f3d38de530f9',
        'pac_status' => 'valid',
        'cancellation_status' => 'none',
        'pac_issue_status' => 'succeeded',
        'pac_reconciliation_required' => false,
        'cfdi_artifacts_status' => 'stored',
    ], $attributes))->save();
    app(CurrentTenant::class)->set($company->id);

    return $invoice->fresh();
}

function phase619CancelResponse(Invoice $invoice, array $overrides = []): array
{
    return array_merge([
        'id' => $invoice->pac_external_id,
        'livemode' => false,
        'status' => 'canceled',
        'cancellation_status' => 'accepted',
        'uuid' => strtoupper((string) $invoice->cfdi_uuid),
    ], $overrides);
}

function phase619Cancel(Invoice $invoice): Invoice
{
    return app(CancelInvoiceWithPacService::class)->cancel($invoice, CfdiCancellationMotive::ErrorsWithoutRelation);
}

function phase619RequestedEvents(): int
{
    return DB::table('invoice_pac_events')
        ->where('event_type', InvoicePacEventType::CancellationRequested->value)
        ->count();
}

test('una segunda cancelación durante el DELETE en curso no envía otro DELETE', function () {
    $invoice = phase619CancelableInvoice();
    $deletes = 0;
    $concurrentError = null;

    Http::fake(function () use ($invoice, &$deletes, &$concurrentError) {
        $deletes++;

        try {
            phase619Cancel($invoice->fresh());
        } catch (Throwable $e) {
            $concurrentError = $e;
        }

        return Http::response(phase619CancelResponse($invoice));
    });

    $result = phase619Cancel($invoice);

    expect($deletes)->toBe(1)
        ->and($concurrentError)->toBeInstanceOf(InvoiceCancellationInProgressException::class)
        ->and($result->pac_status)->toBe('canceled')
        ->and($result->pac_cancel_request_status)->toBe('completed')
        ->and($result->pac_cancel_attempts)->toBe(1)
        ->and(phase619RequestedEvents())->toBe(1);
});

test('la reserva queda confirmada antes del HTTP y el lock ya fue liberado', function () {
    $invoice = phase619CancelableInvoice();
    $baseline = DB::transactionLevel();
    $duringHttp = null;

    Http::fake(function () use ($invoice, &$duringHttp) {
        $row = DB::table('invoices')->where('id', $invoice->id)->first();
        $duringHttp = [
            'level' => DB::transactionLevel(),
            'status' => $row->pac_cancel_request_status,
            'attempts' => (int) $row->pac_cancel_attempts,
            'started' => $row->pac_cancel_started_at !== null,
        ];

        return Http::response(phase619CancelResponse($invoice, ['status' => 'valid', 'cancellation_status' => 'pending']));
    });

    phase619Cancel($invoice);

    expect($duringHttp)->toBe(['level' => $baseline, 'status' => 'in_flight', 'attempts' => 1, 'started' => true]);
});

test('una reserva in_flight previa bloquea sin HTTP ni evento de solicitud', function () {
    $invoice = phase619CancelableInvoice(['pac_cancel_request_status' => 'in_flight', 'pac_cancel_started_at' => now()]);
    Http::fake();

    expect(fn () => phase619Cancel($invoice))->toThrow(InvoiceCancellationInProgressException::class);

    Http::assertNothingSent();
    expect(phase619RequestedEvents())->toBe(0);
});

test('una solicitud ambigua previa exige reconciliar antes de otro DELETE', function () {
    $invoice = phase619CancelableInvoice(['pac_cancel_request_status' => 'reconciliation_required']);
    Http::fake();

    expect(fn () => phase619Cancel($invoice))->toThrow(PacReconciliationRequiredException::class);

    Http::assertNothingSent();
});

test('timeout del DELETE deja la solicitud ambigua y bloquea el siguiente intento', function () {
    $invoice = phase619CancelableInvoice();
    $deletes = 0;
    Http::fake(function () use (&$deletes) {
        $deletes++;

        throw new ConnectionException('cURL error 28: Operation timed out');
    });

    expect(fn () => phase619Cancel($invoice))->toThrow(ConnectionException::class);
    expect(fn () => phase619Cancel($invoice->fresh()))->toThrow(PacReconciliationRequiredException::class);

    $fresh = $invoice->fresh();
    expect($deletes)->toBe(1)
        ->and($fresh->pac_cancel_request_status)->toBe('reconciliation_required')
        ->and($fresh->pac_reconciliation_required)->toBeTrue()
        ->and($fresh->cancellation_status)->toBe('none');
});

test('un rechazo definitivo (400) deja failed y permite una nueva solicitud explícita', function () {
    $invoice = phase619CancelableInvoice();
    Http::fakeSequence()
        ->push(['message' => 'Motivo inválido', 'code' => 'invalid_request'], 400)
        ->push(phase619CancelResponse($invoice));

    expect(fn () => phase619Cancel($invoice))->toThrow(PacValidationException::class);
    expect($invoice->fresh()->pac_cancel_request_status)->toBe('failed');

    $retried = phase619Cancel($invoice->fresh());

    expect($retried->pac_cancel_request_status)->toBe('completed')
        ->and($retried->pac_cancel_attempts)->toBe(2)
        ->and($retried->pac_status)->toBe('canceled');
    Http::assertSentCount(2);
});

test('cancelación pendiente completa la solicitud y no repite el DELETE', function () {
    $invoice = phase619CancelableInvoice();
    Http::fake(['*' => Http::response(phase619CancelResponse($invoice, ['status' => 'valid', 'cancellation_status' => 'pending']))]);

    $pending = phase619Cancel($invoice);

    expect($pending->pac_cancel_request_status)->toBe('completed')
        ->and($pending->cancellation_status)->toBe('pending');
    expect(fn () => phase619Cancel($pending->fresh()))->toThrow(InvoiceCannotBeCancelledException::class);
    Http::assertSentCount(1);
});

test('una respuesta atrasada se descarta pero la solicitud no queda in_flight', function () {
    $invoice = phase619CancelableInvoice();
    Http::fake(function () use ($invoice) {
        // Una reconciliación concurrente ya escribió el estado remoto.
        DB::table('invoices')->where('id', $invoice->id)->update(['cancellation_status' => 'pending']);

        return Http::response(phase619CancelResponse($invoice));
    });

    $result = phase619Cancel($invoice);

    expect($result->pac_status)->toBe('valid')
        ->and($result->cancellation_status)->toBe('pending')
        ->and($result->pac_cancel_request_status)->toBe('completed');
});

test('capabilities: una solicitud abierta bloquea cancelar y habilita reconciliar', function (string $requestStatus) {
    $invoice = phase619CancelableInvoice(['pac_cancel_request_status' => $requestStatus, 'pac_cancel_started_at' => now()]);
    $user = User::factory()->create(['company_id' => $invoice->company_id, 'role' => UserRole::Admin]);

    $actions = app(InvoiceBusinessCapabilitiesService::class)->for($invoice, $user);

    expect($actions['can_cancel'])->toBeFalse()
        ->and($actions['cancellation_mode'])->toBeNull()
        ->and($actions['can_reconcile'])->toBeTrue();
})->with(['in_flight', 'reconciliation_required']);

test('capabilities: completed o failed no bloquean una nueva solicitud válida', function (string $requestStatus) {
    $invoice = phase619CancelableInvoice(['pac_cancel_request_status' => $requestStatus, 'cancellation_status' => 'rejected']);
    $user = User::factory()->create(['company_id' => $invoice->company_id, 'role' => UserRole::Admin]);

    expect(app(InvoiceBusinessCapabilitiesService::class)->for($invoice, $user)['cancellation_mode'])->toBe('pac');
})->with(['completed', 'failed']);

function phase619OperationsCancel(Invoice $invoice)
{
    $user = User::factory()->create(['company_id' => $invoice->company_id, 'role' => UserRole::Admin]);
    app(CurrentTenant::class)->clear();

    return test()->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/operations/cancel", ['confirm' => true, 'motive' => '02']);
}

test('operations/cancel con reserva in_flight previa responde PAC_CANCELLATION_IN_PROGRESS', function () {
    $invoice = phase619CancelableInvoice(['pac_cancel_request_status' => 'in_flight', 'pac_cancel_started_at' => now()]);
    Http::fake();

    phase619OperationsCancel($invoice)
        ->assertStatus(409)
        ->assertJsonPath('code', 'PAC_CANCELLATION_IN_PROGRESS');

    Http::assertNothingSent();
    expect(phase619RequestedEvents())->toBe(0);
});

test('operations/cancel con reserva reconciliation_required responde PAC_RECONCILIATION_REQUIRED', function (bool $flag) {
    // Con y sin el flag general: antes, sin flag, caía en INVOICE_LIFECYCLE_INCONSISTENT.
    $invoice = phase619CancelableInvoice([
        'pac_cancel_request_status' => 'reconciliation_required',
        'pac_reconciliation_required' => $flag,
    ]);
    Http::fake();

    phase619OperationsCancel($invoice)
        ->assertStatus(409)
        ->assertJsonPath('code', 'PAC_RECONCILIATION_REQUIRED');

    Http::assertNothingSent();
})->with(['con flag' => true, 'sin flag' => false]);

test('carrera: la verificación previa pasa pero la reserva bajo lock ya está tomada -> PAC_CANCELLATION_IN_PROGRESS', function () {
    $invoice = phase619CancelableInvoice();
    Http::fake();

    // Otro proceso toma la reserva justo después de la verificación previa
    // del orquestador y antes de reserve() bajo lock.
    app()->bind(InvoiceWorkflow::class, fn ($app) => new class($app->make(IssueInvoiceService::class), $app->make(ReconcileInvoiceWithPacService::class), $app->make(CancelInvoiceWithPacService::class)) extends InvoiceWorkflow
    {
        public function cancelWithPac(Invoice $invoice, CfdiCancellationMotive $motive, ?string $substitutionUuid = null): Invoice
        {
            DB::table('invoices')->where('id', $invoice->id)->update([
                'pac_cancel_request_status' => 'in_flight',
                'pac_cancel_started_at' => now(),
                'pac_cancel_attempts' => 1,
            ]);

            return parent::cancelWithPac($invoice, $motive, $substitutionUuid);
        }
    });

    phase619OperationsCancel($invoice)
        ->assertStatus(409)
        ->assertJsonPath('code', 'PAC_CANCELLATION_IN_PROGRESS');

    $row = DB::table('invoices')->where('id', $invoice->id)->first();
    expect($row->pac_cancel_request_status)->toBe('in_flight')
        ->and((int) $row->pac_cancel_attempts)->toBe(1)
        ->and($row->cancellation_status)->toBe('none')
        ->and($row->status)->toBe('issued')
        ->and(phase619RequestedEvents())->toBe(0);
    Http::assertNothingSent();
});

test('pac/cancel expone el código PAC_CANCELLATION_IN_PROGRESS', function () {
    $invoice = phase619CancelableInvoice(['pac_cancel_request_status' => 'in_flight', 'pac_cancel_started_at' => now()]);
    $user = User::factory()->create(['company_id' => $invoice->company_id, 'role' => UserRole::Admin]);
    Http::fake();
    app(CurrentTenant::class)->clear();

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/pac/cancel", ['confirm' => true, 'motive' => '02'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'PAC_CANCELLATION_IN_PROGRESS');
});

test('reconciliar resuelve la solicitud según el estado remoto', function (
    string $requestStatus,
    int $startedMinutesAgo,
    array $remote,
    ?string $expected,
) {
    $invoice = phase619CancelableInvoice([
        'pac_cancel_request_status' => $requestStatus,
        'pac_cancel_started_at' => now()->subMinutes($startedMinutesAgo),
        'pac_reconciliation_required' => true,
    ]);
    Http::fake(['*' => Http::response(array_merge([
        'id' => $invoice->pac_external_id,
        'livemode' => false,
        'uuid' => $invoice->cfdi_uuid,
    ], $remote))]);

    $result = app(ReconcileInvoiceWithPacService::class)->reconcile($invoice);

    expect($result->pac_cancel_request_status)->toBe($expected);
})->with([
    'ambigua, el PAC registró la solicitud' => ['reconciliation_required', 1, ['status' => 'valid', 'cancellation_status' => 'pending'], 'completed'],
    'ambigua, el PAC ya canceló' => ['reconciliation_required', 1, ['status' => 'canceled', 'cancellation_status' => 'accepted'], 'completed'],
    'ambigua, el PAC no la registró' => ['reconciliation_required', 1, ['status' => 'valid', 'cancellation_status' => 'none'], null],
    'in_flight reciente sin registro remoto se conserva' => ['in_flight', 1, ['status' => 'valid', 'cancellation_status' => 'none'], 'in_flight'],
    'in_flight abandonada sin registro remoto se libera' => ['in_flight', 5, ['status' => 'valid', 'cancellation_status' => 'none'], null],
    'in_flight con registro remoto se completa' => ['in_flight', 1, ['status' => 'valid', 'cancellation_status' => 'verifying'], 'completed'],
]);
