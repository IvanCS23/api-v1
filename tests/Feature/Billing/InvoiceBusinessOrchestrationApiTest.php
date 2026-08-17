<?php

use App\Enums\InvoicePacEventType;
use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Exceptions\InvoiceIssuanceInProgressException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Billing\OrchestrateInvoiceIssuanceService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_PHASE_616_SECRET',
    ]);

    Http::preventStrayRequests();
});

/** @return array{Company, User, Invoice} */
function phase616Invoice(array $overrides = [], UserRole $role = UserRole::Admin): array
{
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'role' => $role]);
    $invoice = Invoice::factory()->create(array_merge([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Ready,
        'subtotal' => 100,
        'tax_total' => 0,
        'total' => 100,
    ], $overrides));
    InvoiceItem::factory()->create([
        'company_id' => $company->id,
        'invoice_id' => $invoice->id,
        'subtotal' => 100,
        'total' => 100,
    ]);
    app(CurrentTenant::class)->set($company->id);

    return [$company, $user, $invoice->fresh()];
}

function phase616SuccessfulIssueFake(Invoice $invoice, ?callable $duringStamp = null): string
{
    $draftId = 'inv_business_'.$invoice->id;

    Http::fake(function (Request $request) use ($invoice, $draftId, $duringStamp) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if ($request->method() === 'POST' && $path === '/v2/invoices') {
            return Http::response([
                'id' => $draftId,
                'status' => 'draft',
                'livemode' => false,
                'is_ready_to_stamp' => true,
                'total' => 100,
            ]);
        }

        if ($request->method() === 'GET' && $path === "/v2/invoices/{$draftId}") {
            return Http::response([
                'id' => $draftId,
                'status' => 'draft',
                'livemode' => false,
                'is_ready_to_stamp' => true,
                'total' => 100,
            ]);
        }

        if ($request->method() === 'POST' && $path === "/v2/invoices/{$draftId}/stamp") {
            if ($duringStamp !== null) {
                $duringStamp();
            }

            return Http::response([
                'id' => $draftId,
                'status' => 'valid',
                'livemode' => false,
                'uuid' => 'AAAAAAAA-1111-4222-8333-444444444444',
                'stamp' => ['date' => '2026-08-14T12:00:00Z'],
            ]);
        }

        return Http::response([], 599);
    });

    return $draftId;
}

test('emisión empresarial ready termina únicamente con ERP y CFDI confirmados', function () {
    [, $user, $invoice] = phase616Invoice();
    phase616SuccessfulIssueFake($invoice);
    app(CurrentTenant::class)->clear();

    $response = $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/operations/issue", ['confirm' => true])
        ->assertOk()
        ->assertJsonPath('status', 'issued')
        ->assertJsonPath('pac.status', 'valid')
        ->assertJsonPath('pac.issue_status', 'succeeded')
        ->assertJsonPath('pac.reconciliation_required', false)
        ->assertJsonPath('cfdi.uuid', 'AAAAAAAA-1111-4222-8333-444444444444')
        ->assertJsonPath('actions.can_issue', false);

    app(CurrentTenant::class)->set($invoice->company_id);
    $fresh = $invoice->fresh();
    expect($fresh->issued_at)->not->toBeNull()
        ->and($fresh->pacEvents()->where('event_type', InvoicePacEventType::StampSucceeded->value)->count())->toBe(1)
        ->and($response->getContent())->not->toContain('sk_test_PHASE_616_SECRET', 'pac_response');
    Http::assertSentCount(3);
});

test('emisión empresarial repetida devuelve el mismo UUID sin segundo stamp ni evento', function () {
    [, $user, $invoice] = phase616Invoice();
    phase616SuccessfulIssueFake($invoice);
    app(CurrentTenant::class)->clear();
    $uri = "/api/invoices/{$invoice->id}/operations/issue";

    $first = $this->actingAs($user, 'api')->postJson($uri, ['confirm' => true])->assertOk();
    $sent = Http::recorded()->count();
    $second = $this->actingAs($user, 'api')->postJson($uri, ['confirm' => true])->assertOk();

    app(CurrentTenant::class)->set($invoice->company_id);
    expect($second->json('cfdi.uuid'))->toBe($first->json('cfdi.uuid'))
        ->and(Http::recorded())->toHaveCount($sent)
        ->and($invoice->pacEvents()->where('event_type', InvoicePacEventType::StampSucceeded->value)->count())->toBe(1);
});

test('reserva concurrente impide un segundo stamp lógico', function () {
    [, $user, $invoice] = phase616Invoice();
    $concurrentBlocked = false;
    phase616SuccessfulIssueFake($invoice, function () use ($invoice, &$concurrentBlocked): void {
        try {
            app(OrchestrateInvoiceIssuanceService::class)->issue($invoice->fresh());
        } catch (InvoiceIssuanceInProgressException) {
            $concurrentBlocked = true;
        }
    });
    app(CurrentTenant::class)->clear();

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/operations/issue", ['confirm' => true])
        ->assertOk();

    expect($concurrentBlocked)->toBeTrue();
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/stamp'));
    expect(Http::recorded()->filter(fn (array $record): bool => str_ends_with($record[0]->url(), '/stamp')))->toHaveCount(1);
});

test('timeout de stamp deja ERP issued y exige reconciliación sin reintento ciego', function () {
    [, $user, $invoice] = phase616Invoice();
    $draftId = 'inv_timeout_'.$invoice->id;
    Http::fake(function (Request $request) use ($draftId) {
        if (str_ends_with($request->url(), '/stamp')) {
            throw new ConnectionException('timeout privado');
        }

        return Http::response([
            'id' => $draftId,
            'status' => 'draft',
            'livemode' => false,
            'is_ready_to_stamp' => true,
            'total' => 100,
        ]);
    });
    app(CurrentTenant::class)->clear();
    $uri = "/api/invoices/{$invoice->id}/operations/issue";

    $this->actingAs($user, 'api')->postJson($uri, ['confirm' => true])
        ->assertConflict()->assertJsonPath('code', 'PAC_RECONCILIATION_REQUIRED');
    $sentAfterFailure = Http::recorded()->count();
    $this->actingAs($user, 'api')->postJson($uri, ['confirm' => true])
        ->assertConflict()->assertJsonPath('code', 'PAC_RECONCILIATION_REQUIRED');

    app(CurrentTenant::class)->set($invoice->company_id);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->fresh()->cfdi_uuid)->toBeNull()
        ->and($invoice->fresh()->pac_reconciliation_required)->toBeTrue()
        ->and(Http::recorded())->toHaveCount($sentAfterFailure);
});

test('readiness inválida conserva ERP ready y hace cero HTTP', function () {
    [, $user, $invoice] = phase616Invoice(['payment_form' => null]);
    app(CurrentTenant::class)->clear();

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/operations/issue", ['confirm' => true])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'INVOICE_NOT_READY_FOR_PAC');

    app(CurrentTenant::class)->set($invoice->company_id);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Ready);
    Http::assertNothingSent();
});

test('rechazo definitivo al crear draft deja ERP issued pero reintento empresarial seguro habilitado', function () {
    [, $user, $invoice] = phase616Invoice();
    Http::fake(['*' => Http::response(['message' => 'dato fiscal rechazado'], 422)]);
    app(CurrentTenant::class)->clear();

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/operations/issue", ['confirm' => true])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'PAC_VALIDATION_FAILED');

    app(CurrentTenant::class)->set($invoice->company_id);
    $fresh = $invoice->fresh();
    expect($fresh->status)->toBe(InvoiceStatus::Issued)
        ->and($fresh->cfdi_uuid)->toBeNull()
        ->and($fresh->pac_reconciliation_required)->toBeFalse()
        ->and(app(\App\Services\Billing\InvoiceBusinessCapabilitiesService::class)->canIssueByState($fresh))->toBeTrue();
    Http::assertSentCount(1);
});

test('cancelación empresarial sin CFDI sólo cancela ERP', function () {
    [, $user, $invoice] = phase616Invoice();
    app(CurrentTenant::class)->clear();

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/operations/cancel", ['confirm' => true])
        ->assertOk()
        ->assertJsonPath('status', 'cancelled')
        ->assertJsonPath('cfdi.uuid', null);

    Http::assertNothingSent();
});

test('cancelación empresarial con CFDI aceptado converge PAC y ERP', function () {
    [, $user, $invoice] = phase616Invoice(['status' => InvoiceStatus::Issued]);
    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_cancel_'.$invoice->id,
        'cfdi_uuid' => 'BBBBBBBB-1111-4222-8333-444444444444',
        'pac_status' => 'valid',
        'pac_issue_status' => 'succeeded',
        'cancellation_status' => 'none',
    ])->save();
    Http::fake(['*' => Http::response([
        'id' => $invoice->pac_external_id,
        'status' => 'canceled',
        'livemode' => false,
        'uuid' => $invoice->cfdi_uuid,
        'cancellation_status' => 'accepted',
    ])]);
    app(CurrentTenant::class)->clear();

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/operations/cancel", ['confirm' => true, 'motive' => '02'])
        ->assertOk()
        ->assertJsonPath('status', 'cancelled')
        ->assertJsonPath('pac.status', 'canceled')
        ->assertJsonPath('pac.cancellation_status', 'accepted');

    Http::assertSentCount(1);
});

test('cancelación PAC pending responde 202 y no marca ERP cancelled', function () {
    [, $user, $invoice] = phase616Invoice(['status' => InvoiceStatus::Issued]);
    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_pending_'.$invoice->id,
        'cfdi_uuid' => 'CCCCCCCC-1111-4222-8333-444444444444',
        'pac_status' => 'valid',
        'pac_issue_status' => 'succeeded',
        'cancellation_status' => 'none',
    ])->save();
    Http::fake(['*' => Http::response([
        'id' => $invoice->pac_external_id,
        'status' => 'valid',
        'livemode' => false,
        'uuid' => $invoice->cfdi_uuid,
        'cancellation_status' => 'pending',
    ])]);
    app(CurrentTenant::class)->clear();

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/operations/cancel", ['confirm' => true, 'motive' => '03'])
        ->assertStatus(202)
        ->assertJsonPath('status', 'issued')
        ->assertJsonPath('pac.cancellation_status', 'pending')
        ->assertJsonPath('pac.reconciliation_required', true)
        ->assertJsonPath('actions.can_cancel', false);
});

test('cancelación fiscal rechazada devuelve conflicto y mantiene ERP issued y CFDI valid', function () {
    [, $user, $invoice] = phase616Invoice(['status' => InvoiceStatus::Issued]);
    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_rejected_'.$invoice->id,
        'cfdi_uuid' => 'ABABABAB-1111-4222-8333-444444444444',
        'pac_status' => 'valid',
        'pac_issue_status' => 'succeeded',
        'cancellation_status' => 'none',
    ])->save();
    Http::fake(['*' => Http::response([
        'id' => $invoice->pac_external_id,
        'status' => 'valid',
        'livemode' => false,
        'uuid' => $invoice->cfdi_uuid,
        'cancellation_status' => 'rejected',
    ])]);
    app(CurrentTenant::class)->clear();

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/operations/cancel", ['confirm' => true, 'motive' => '04'])
        ->assertConflict()
        ->assertJsonPath('code', 'INVOICE_CANCELLATION_NOT_COMPLETED');

    app(CurrentTenant::class)->set($invoice->company_id);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->fresh()->pac_status)->toBe('valid')
        ->and($invoice->fresh()->cancellation_status)->toBe('rejected');
});

test('CFDI ya cancelado converge ERP idempotentemente sin otra llamada PAC', function () {
    [, $user, $invoice] = phase616Invoice(['status' => InvoiceStatus::Issued]);
    $invoice->forceFill([
        'pac_external_id' => 'inv_already_canceled_'.$invoice->id,
        'cfdi_uuid' => 'DDDDDDDD-1111-4222-8333-444444444444',
        'pac_status' => 'canceled',
        'pac_issue_status' => 'succeeded',
        'cancellation_status' => 'accepted',
    ])->save();
    app(CurrentTenant::class)->clear();
    $uri = "/api/invoices/{$invoice->id}/operations/cancel";

    $this->actingAs($user, 'api')->postJson($uri, ['confirm' => true])->assertOk()->assertJsonPath('status', 'cancelled');
    $this->actingAs($user, 'api')->postJson($uri, ['confirm' => true])->assertOk()->assertJsonPath('status', 'cancelled');

    Http::assertNothingSent();
});

test('operaciones empresariales respetan autenticación tenant y abilities', function () {
    [$company, $admin, $invoice] = phase616Invoice();
    $foreignCompany = Company::factory()->create();
    $foreign = User::factory()->create(['company_id' => $foreignCompany->id, 'role' => UserRole::Admin]);
    $employee = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Employee]);
    app(CurrentTenant::class)->clear();

    $this->postJson("/api/invoices/{$invoice->id}/operations/issue", ['confirm' => true])->assertUnauthorized();
    $this->actingAs($foreign, 'api')->postJson("/api/invoices/{$invoice->id}/operations/issue", ['confirm' => true])->assertNotFound();
    $this->actingAs($employee, 'api')->postJson("/api/invoices/{$invoice->id}/operations/issue", ['confirm' => true])->assertForbidden();
    $this->actingAs($admin, 'api')->postJson("/api/invoices/{$invoice->id}/operations/cancel", ['confirm' => true])->assertOk();

    Http::assertNothingSent();
});

test('combinaciones draft o ready con CFDI válido no pueden emitir y sólo permiten reparación por cancelación fiscal', function (InvoiceStatus $status) {
    [, $user, $invoice] = phase616Invoice(['status' => $status]);
    $invoice->forceFill([
        'pac_external_id' => 'inv_invalid_'.$invoice->id,
        'cfdi_uuid' => 'EEEEEEEE-1111-4222-8333-444444444444',
        'pac_status' => 'valid',
        'pac_issue_status' => 'succeeded',
        'cancellation_status' => 'none',
    ])->save();
    app(CurrentTenant::class)->clear();

    $this->actingAs($user, 'api')->getJson("/api/invoices/{$invoice->id}/billing")
        ->assertOk()
        ->assertJsonPath('actions.can_issue', false)
        ->assertJsonPath('actions.can_cancel', true)
        ->assertJsonPath('actions.cancellation_mode', 'pac');
    $this->actingAs($user, 'api')->postJson("/api/invoices/{$invoice->id}/operations/issue", ['confirm' => true])
        ->assertConflict()->assertJsonPath('code', 'INVOICE_LIFECYCLE_INCONSISTENT');

    Http::assertNothingSent();
})->with([InvoiceStatus::Draft, InvoiceStatus::Ready]);
