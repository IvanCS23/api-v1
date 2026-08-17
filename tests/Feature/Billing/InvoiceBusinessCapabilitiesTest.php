<?php

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\InvoiceBusinessCapabilitiesService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('matriz ERP PAC deriva capacidades empresariales sin consultar PAC', function (
    InvoiceStatus $erp,
    array $fiscal,
    bool $canIssue,
    ?string $cancelMode,
    bool $canReconcile,
) {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Admin]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => $erp]);
    $invoice->forceFill($fiscal)->save();
    app(CurrentTenant::class)->set($company->id);

    $actions = app(InvoiceBusinessCapabilitiesService::class)->for($invoice->fresh(), $user);

    expect($actions)->toBe([
        'can_issue' => $canIssue,
        'can_cancel' => $cancelMode !== null,
        'can_reconcile' => $canReconcile,
        'cancellation_mode' => $cancelMode,
    ]);
    Http::assertNothingSent();
})->with([
    'draft sin CFDI válido ERP-only' => [InvoiceStatus::Draft, [], false, 'erp_only', false],
    'ready sin CFDI válido y emitible' => [InvoiceStatus::Ready, [], true, 'erp_only', false],
    'issued sin CFDI recuperable' => [InvoiceStatus::Issued, [], true, 'erp_only', false],
    'issued CFDI valid cancelable por PAC' => [InvoiceStatus::Issued, [
        'pac_external_id' => 'inv_valid', 'cfdi_uuid' => 'AAAAAAAA-1111-4222-8333-444444444444',
        'pac_status' => 'valid', 'pac_issue_status' => 'succeeded',
    ], false, 'pac', false],
    'issued emisión pending transitorio' => [InvoiceStatus::Issued, [
        'pac_issue_status' => 'pending',
    ], false, null, false],
    'issued ambiguo recuperable por reconciliación' => [InvoiceStatus::Issued, [
        'pac_draft_external_id' => 'inv_draft_reconcile', 'pac_issue_status' => 'reconciliation_required',
        'pac_reconciliation_required' => true,
    ], false, null, true],
    'issued con external id parcial recuperable por reconciliación' => [InvoiceStatus::Issued, [
        'pac_external_id' => 'inv_partial_external',
    ], false, null, true],
    'issued con draft remoto valid recuperable por reconciliación' => [InvoiceStatus::Issued, [
        'pac_draft_external_id' => 'inv_draft_valid', 'pac_draft_status' => 'valid',
    ], false, null, true],
    'issued CFDI canceled converge ERP' => [InvoiceStatus::Issued, [
        'pac_external_id' => 'inv_canceled', 'cfdi_uuid' => 'BBBBBBBB-1111-4222-8333-444444444444',
        'pac_status' => 'canceled', 'pac_issue_status' => 'succeeded', 'cancellation_status' => 'accepted',
    ], false, 'erp_convergence', false],
    'cancelled sin CFDI final' => [InvoiceStatus::Cancelled, [], false, null, false],
    'cancelled CFDI canceled final' => [InvoiceStatus::Cancelled, [
        'pac_external_id' => 'inv_canceled_final', 'cfdi_uuid' => 'CCCCCCCC-1111-4222-8333-444444444444',
        'pac_status' => 'canceled', 'pac_issue_status' => 'succeeded', 'cancellation_status' => 'accepted',
    ], false, null, false],
    'cancelled CFDI valid inválido pero reparable' => [InvoiceStatus::Cancelled, [
        'pac_external_id' => 'inv_danger', 'cfdi_uuid' => 'DDDDDDDD-1111-4222-8333-444444444444',
        'pac_status' => 'valid', 'pac_issue_status' => 'succeeded',
    ], false, 'pac', false],
    'ready con CFDI valid inválido pero reparable' => [InvoiceStatus::Ready, [
        'pac_external_id' => 'inv_ready_invalid', 'cfdi_uuid' => 'EEEEEEEE-1111-4222-8333-444444444444',
        'pac_status' => 'valid', 'pac_issue_status' => 'succeeded',
    ], false, 'pac', false],
]);

test('identidad fiscal parcial queda inválida y sin acciones automáticas', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Admin]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    $invoice->forceFill(['cfdi_uuid' => 'FFFFFFFF-1111-4222-8333-444444444444'])->save();
    app(CurrentTenant::class)->set($company->id);

    expect(app(InvoiceBusinessCapabilitiesService::class)->for($invoice->fresh(), $user))->toBe([
        'can_issue' => false,
        'can_cancel' => false,
        'can_reconcile' => false,
        'cancellation_mode' => null,
    ]);
    Http::assertNothingSent();
});
