<?php

use App\Enums\InvoicePacEventType;
use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePacEvent;
use App\Models\User;
use App\Services\Billing\InvoiceBusinessCapabilitiesService;
use App\Support\Billing\PacIdentifiers;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Fase 6.29 — "Regresar a lista": una factura issued cuyo timbrado fue
 * rechazado vuelve a ready (para eliminarla y volver a facturar la venta, o
 * reintentar), sólo si su borrador remoto sigue sin timbrar.
 */
beforeEach(function () {
    facturapiOrganizationsFromConfigKey();
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_PHASE_629_REVERT',
    ]);
    Http::preventStrayRequests();
});

/**
 * Factura issued sin CFDI tras un /stamp rechazado (borrador remoto vigente).
 *
 * @return array{User, Invoice}
 */
function phase629Rejected(array $overrides = [], UserRole $role = UserRole::Admin): array
{
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'role' => $role]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);
    $invoice->forceFill(array_merge([
        'issued_at' => now()->subHour(),
        'pac_provider' => 'facturapi',
        'pac_draft_external_id' => 'inv_rev_'.$invoice->id,
        'pac_draft_idempotency_key' => PacIdentifiers::draftIdempotencyKey($company->id, $invoice->id),
        'pac_draft_status' => 'draft',
        'pac_draft_ready_to_stamp' => true,
        'pac_issue_status' => 'failed',
        'pac_issue_attempts' => 1,
        'pac_last_error' => '[cfdi40139] El nombre del receptor no coincide con su RFC.',
    ], $overrides))->save();
    app(CurrentTenant::class)->set($company->id);

    return [$user, $invoice->fresh()];
}

function phase629FakeRemote(Invoice $invoice, string $status): void
{
    Http::fake(['*' => Http::response(array_filter([
        'id' => $invoice->pac_draft_external_id,
        'status' => $status,
        'livemode' => false,
        'is_ready_to_stamp' => $status === 'draft' ? true : null,
    ], fn ($value) => $value !== null))]);
}

function phase629Revert(User $user, Invoice $invoice)
{
    app(CurrentTenant::class)->clear();

    return test()->actingAs($user, 'api')->postJson("/api/invoices/{$invoice->id}/operations/revert-issue", ['confirm' => true]);
}

test('una factura con el timbrado rechazado regresa a ready tras confirmar que el borrador sigue sin timbrar', function () {
    [$user, $invoice] = phase629Rejected();
    phase629FakeRemote($invoice, 'draft');

    phase629Revert($user, $invoice)
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('actions.can_revert_issue', false)
        ->assertJsonPath('actions.can_issue', true);

    $row = (array) DB::table('invoices')->where('id', $invoice->id)->first();
    $event = InvoicePacEvent::withoutGlobalScopes()->where('invoice_id', $invoice->id)->where('event_type', InvoicePacEventType::IssueReverted->value)->first();

    expect($row['status'])->toBe('ready')
        ->and($row['issued_at'])->toBeNull()
        ->and($row['pac_issue_status'])->toBeNull()
        // Se conserva el rastro: borrador, intentos y último error.
        ->and($row['pac_draft_external_id'])->toBe($invoice->pac_draft_external_id)
        ->and((int) $row['pac_issue_attempts'])->toBe(1)
        ->and($row['pac_last_error'])->not->toBeNull()
        ->and($event)->not->toBeNull()
        ->and($event->context)->toBe(['previous_issue_status' => 'failed', 'pac_draft_status' => 'draft']);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET');
});

test('después de regresar a ready la factura se puede eliminar', function () {
    [$user, $invoice] = phase629Rejected();
    phase629FakeRemote($invoice, 'draft');
    phase629Revert($user, $invoice)->assertOk();

    $this->actingAs($user, 'api')->deleteJson("/api/invoices/{$invoice->id}")->assertNoContent();

    expect(Invoice::withoutGlobalScopes()->withTrashed()->find($invoice->id)->trashed())->toBeTrue();
});

test('si el borrador remoto ya se timbró no regresa: queda registrado para reconciliar', function () {
    [$user, $invoice] = phase629Rejected();
    phase629FakeRemote($invoice, 'valid');

    phase629Revert($user, $invoice)
        ->assertStatus(409)
        ->assertJsonPath('code', 'INVOICE_CANNOT_BE_REVERTED');

    $fresh = $invoice->fresh();
    expect($fresh->status)->toBe(InvoiceStatus::Issued)
        ->and($fresh->pac_draft_status)->toBe('valid')
        ->and(app(InvoiceBusinessCapabilitiesService::class)->for($fresh, $user)['can_reconcile'])->toBeTrue();
});

test('una factura issued sin borrador remoto regresa sin consultar al PAC', function () {
    [$user, $invoice] = phase629Rejected([
        'pac_draft_external_id' => null,
        'pac_draft_idempotency_key' => null,
        'pac_draft_status' => null,
        'pac_issue_status' => null,
    ]);
    Http::fake();

    phase629Revert($user, $invoice)->assertOk()->assertJsonPath('status', 'ready');

    Http::assertNothingSent();
});

test('no regresa sin consultar al PAC cuando el estado local no lo permite', function (array $overrides) {
    [$user, $invoice] = phase629Rejected($overrides);
    Http::fake();

    phase629Revert($user, $invoice)->assertStatus(409)->assertJsonPath('code', 'INVOICE_CANNOT_BE_REVERTED');

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Issued);
    Http::assertNothingSent();
})->with([
    'con CFDI' => [['pac_external_id' => 'inv_x', 'cfdi_uuid' => 'AAAAAAAA-1111-4222-8333-444444444444', 'pac_status' => 'valid', 'pac_issue_status' => 'succeeded', 'pac_draft_status' => 'valid']],
    'timbrado en curso' => [['pac_issue_status' => 'pending']],
    'en revisión' => [['pac_issue_status' => 'reconciliation_required', 'pac_reconciliation_required' => true]],
    'borrador remoto pendiente' => [['pac_draft_status' => 'pending']],
    'llave de borrador reservada sin id remoto' => [['pac_draft_external_id' => null, 'pac_draft_status' => null, 'pac_issue_status' => null]],
]);

test('una factura que no está issued no regresa', function (InvoiceStatus $status) {
    [$user, $invoice] = phase629Rejected(['status' => $status]);
    Http::fake();

    phase629Revert($user, $invoice)->assertStatus(409)->assertJsonPath('code', 'INVOICE_CANNOT_BE_REVERTED');
})->with([InvoiceStatus::Ready, InvoiceStatus::Cancelled]);

test('sólo Admin y Contador pueden regresar una factura; exige confirm', function () {
    [$user, $invoice] = phase629Rejected(role: UserRole::Sales);
    Http::fake();

    phase629Revert($user, $invoice)->assertForbidden();

    [$admin, $other] = phase629Rejected();
    app(CurrentTenant::class)->clear();
    $this->actingAs($admin, 'api')->postJson("/api/invoices/{$other->id}/operations/revert-issue", [])->assertUnprocessable();

    expect($other->fresh()->status)->toBe(InvoiceStatus::Issued);
});

test('can_revert_issue se ofrece sólo para facturas issued sin CFDI ni operación en curso', function () {
    [$user, $invoice] = phase629Rejected();

    expect(app(InvoiceBusinessCapabilitiesService::class)->for($invoice, $user)['can_revert_issue'])->toBeTrue();

    $invoice->forceFill(['pac_issue_status' => 'pending'])->save();
    expect(app(InvoiceBusinessCapabilitiesService::class)->for($invoice->fresh(), $user)['can_revert_issue'])->toBeFalse();
});
