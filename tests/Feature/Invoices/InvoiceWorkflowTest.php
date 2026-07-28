<?php

use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use App\Services\Billing\InvoiceWorkflow;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;

test('markReady transiciona Draft a Ready', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Draft]);

    $response = $this->actingAs($user, 'api')->postJson("/api/invoices/{$invoice->id}/ready");

    $response->assertOk()->assertJsonPath('status', 'ready');
});

test('issue transiciona Ready a Issued y fija issued_at', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Ready]);

    expect($invoice->fresh()->issued_at)->toBeNull();

    $response = $this->actingAs($user, 'api')->postJson("/api/invoices/{$invoice->id}/issue");

    $response->assertOk()->assertJsonPath('status', 'issued');
    expect($invoice->fresh()->issued_at)->not->toBeNull()
        ->and($invoice->fresh()->cancelled_at)->toBeNull();
});

test('cancel transiciona Draft o Ready a Cancelled y fija cancelled_at', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    $draft = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Draft]);
    $this->actingAs($user, 'api')->postJson("/api/invoices/{$draft->id}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
    expect($draft->fresh()->cancelled_at)->not->toBeNull()
        ->and($draft->fresh()->issued_at)->toBeNull();

    $ready = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Ready]);
    $this->actingAs($user, 'api')->postJson("/api/invoices/{$ready->id}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
    expect($ready->fresh()->cancelled_at)->not->toBeNull();
});

test('Issued es terminal: no admite ready, issue ni cancel de nuevo, y sus timestamps no cambian', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued, 'issued_at' => now()]);
    $issuedAt = $invoice->issued_at;

    $this->actingAs($user, 'api')->postJson("/api/invoices/{$invoice->id}/ready")->assertStatus(422);
    $this->actingAs($user, 'api')->postJson("/api/invoices/{$invoice->id}/issue")->assertStatus(422);
    $this->actingAs($user, 'api')->postJson("/api/invoices/{$invoice->id}/cancel")->assertStatus(422);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->fresh()->issued_at->equalTo($issuedAt))->toBeTrue()
        ->and($invoice->fresh()->cancelled_at)->toBeNull();
});

test('Cancelled es terminal: no admite ready, issue ni cancel de nuevo, y su timestamp no cambia', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Cancelled, 'cancelled_at' => now()]);
    $cancelledAt = $invoice->cancelled_at;

    $this->actingAs($user, 'api')->postJson("/api/invoices/{$invoice->id}/ready")->assertStatus(422);
    $this->actingAs($user, 'api')->postJson("/api/invoices/{$invoice->id}/issue")->assertStatus(422);
    $this->actingAs($user, 'api')->postJson("/api/invoices/{$invoice->id}/cancel")->assertStatus(422);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Cancelled)
        ->and($invoice->fresh()->cancelled_at->equalTo($cancelledAt))->toBeTrue();
});

test('transiciones inválidas: Draft no puede emitirse directamente sin pasar por Ready', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Draft]);

    $response = $this->actingAs($user, 'api')->postJson("/api/invoices/{$invoice->id}/issue");

    $response->assertStatus(422);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Draft);
});

test('el workflow no puede bloquear ni modificar una Invoice de otra empresa aunque la instancia llegue con company_id manipulado', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $companyA->id, 'status' => InvoiceStatus::Draft]);

    $tamperedInvoice = Invoice::withoutGlobalScope(CompanyScope::class)->find($invoice->id);
    $tamperedInvoice->company_id = $companyB->id;

    $workflow = app(InvoiceWorkflow::class);

    expect(fn () => $workflow->markReady($tamperedInvoice))
        ->toThrow(ModelNotFoundException::class);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Draft);
});
