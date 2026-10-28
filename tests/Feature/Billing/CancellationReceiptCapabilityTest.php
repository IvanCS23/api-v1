<?php

use App\Enums\InvoiceHealthAction;
use App\Enums\InvoiceHealthStatus;
use App\Enums\InvoicePacEventType;
use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\InvoiceBusinessCapabilitiesService;
use App\Services\Billing\InvoiceHealthEvaluator;
use App\Services\Billing\InvoicePacAuditService;
use App\Support\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Fase 6.22: can_fetch_cancellation_receipt la calcula el backend. En modo
 * test un solo bloqueo de identidad apaga el botón (Facturapi TEST siempre
 * entrega un acuse ajeno); en live se permiten hasta 3 bloqueos
 * (billing.cancellation_receipt.live_identity_mismatch_limit) y después
 * queda la revisión manual. pac.mode publica el modo sin la llave.
 */
beforeEach(function () {
    facturapiOrganizationsFromConfigKey();
    config([
        'services.facturapi.test_key' => 'sk_test_PHASE_622_CAPABILITY',
        'billing.pac_mode' => 'test',
        'billing.cancellation_receipt.live_identity_mismatch_limit' => 3,
    ]);
    Http::preventStrayRequests();
});

const PHASE_622_RECEIPT_MISMATCH = '[CANCELLATION_RECEIPT_UUID_MISMATCH] El UUID fiscal identificado en el acuse no corresponde al CFDI local para la factura [1].';

/** @return array{Invoice, User} */
function phase622ReceiptCapabilityInvoice(array $overrides = [], UserRole $role = UserRole::Admin, int $mismatches = 0): array
{
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'role' => $role]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Cancelled]);
    $invoice->forceFill(array_merge([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_622_cap_'.$invoice->id,
        'cfdi_uuid' => 'fced601d-c4f6-4ce7-8f05-f3d38de530f9',
        'pac_status' => 'canceled',
        'pac_issue_status' => 'succeeded',
        'cancellation_status' => 'accepted',
        'pac_cancel_request_status' => 'completed',
    ], $mismatches > 0 ? [
        'cancellation_receipt_status' => 'reconciliation_required',
        'cancellation_receipt_last_error' => PHASE_622_RECEIPT_MISMATCH,
    ] : [], $overrides))->save();
    app(CurrentTenant::class)->set($company->id);

    foreach (range(1, $mismatches) as $_) {
        if ($mismatches > 0) {
            app(InvoicePacAuditService::class)->append($invoice, InvoicePacEventType::CancellationReceiptIdentityMismatch, ['receipt_uuid_count' => 1]);
        }
    }

    return [$invoice->fresh(), $user];
}

function phase622CanFetchReceipt(Invoice $invoice, User $user): bool
{
    return app(InvoiceBusinessCapabilitiesService::class)->for($invoice, $user)['can_fetch_cancellation_receipt'];
}

test('can_fetch_cancellation_receipt según el estado fiscal y del acuse', function (array $attributes, bool $expected) {
    // La fecha se calcula aquí: los datasets se evalúan al cargar el archivo.
    if (array_key_exists('started_minutes_ago', $attributes)) {
        $attributes['cancellation_receipt_started_at'] = now()->subMinutes($attributes['started_minutes_ago']);
        unset($attributes['started_minutes_ago']);
    }

    [$invoice, $user] = phase622ReceiptCapabilityInvoice($attributes);

    expect(phase622CanFetchReceipt($invoice, $user))->toBe($expected);
})->with([
    'cancelado y aceptado sin acuse' => [[], true],
    'acuse no disponible (sin bloqueo de identidad)' => [['cancellation_receipt_status' => 'reconciliation_required', 'cancellation_receipt_last_error' => 'El acuse todavía no está disponible.'], true],
    'acuse almacenado' => [['cancellation_receipt_status' => 'stored'], false],
    'descarga vigente' => [['cancellation_receipt_status' => 'pending', 'started_minutes_ago' => 2], false],
    'descarga abandonada' => [['cancellation_receipt_status' => 'pending', 'started_minutes_ago' => 60], true],
    'cancelación pending' => [['pac_status' => 'valid', 'cancellation_status' => 'pending'], false],
    'cancelación rechazada' => [['pac_status' => 'valid', 'cancellation_status' => 'rejected'], false],
    'sin identidad fiscal' => [['pac_external_id' => null, 'cfdi_uuid' => null], false],
]);

test('can_fetch_cancellation_receipt exige permiso de gestión PAC', function (UserRole $role, bool $expected) {
    [$invoice, $user] = phase622ReceiptCapabilityInvoice(role: $role);

    expect(phase622CanFetchReceipt($invoice, $user))->toBe($expected);
})->with([
    'admin' => [UserRole::Admin, true],
    'accountant' => [UserRole::Accountant, true],
    'sales' => [UserRole::Sales, false],
    'employee' => [UserRole::Employee, false],
]);

test('modo test: el primer bloqueo de identidad apaga el botón', function () {
    [$invoice, $user] = phase622ReceiptCapabilityInvoice(mismatches: 1);

    expect(phase622CanFetchReceipt($invoice, $user))->toBeFalse();
});

test('modo live: se permite reintentar hasta 3 bloqueos de identidad', function (int $mismatches, bool $expected) {
    config(['billing.pac_mode' => 'live']);
    [$invoice, $user] = phase622ReceiptCapabilityInvoice(mismatches: $mismatches);

    expect(phase622CanFetchReceipt($invoice, $user))->toBe($expected);
})->with([
    '1 bloqueo' => [1, true],
    '2 bloqueos' => [2, true],
    '3 bloqueos' => [3, false],
    '4 bloqueos' => [4, false],
]);

test('modo live: al llegar al límite billing:health sugiere revisión manual', function () {
    config(['billing.pac_mode' => 'live']);
    [$invoice] = phase622ReceiptCapabilityInvoice(mismatches: 3);

    $finding = app(InvoiceHealthEvaluator::class)
        ->evaluate($invoice, CarbonImmutable::now())
        ->find('cancellation_receipt_identity_mismatch');

    expect($finding->action)->toBe(InvoiceHealthAction::ManualReview)
        ->and($finding->status)->toBe(InvoiceHealthStatus::Recoverable);
});

test('GET /billing publica pac.mode y la capability, nunca la llave', function (string $mode) {
    config(['billing.pac_mode' => $mode]);
    [$invoice, $user] = phase622ReceiptCapabilityInvoice();
    app(CurrentTenant::class)->clear();

    $response = $this->actingAs($user, 'api')
        ->getJson("/api/invoices/{$invoice->id}/billing")
        ->assertOk()
        ->assertJsonPath('pac.mode', $mode)
        ->assertJsonPath('actions.can_fetch_cancellation_receipt', true);

    expect($response->getContent())->not->toContain('sk_test_PHASE_622_CAPABILITY')
        ->not->toContain('sk_test_');
})->with(['test', 'live']);

test('pac.mode es null con un modo no reconocido', function () {
    config(['billing.pac_mode' => 'staging']);
    [$invoice, $user] = phase622ReceiptCapabilityInvoice();
    app(CurrentTenant::class)->clear();

    $this->actingAs($user, 'api')
        ->getJson("/api/invoices/{$invoice->id}/billing")
        ->assertOk()
        ->assertJsonPath('pac.mode', null);
});
