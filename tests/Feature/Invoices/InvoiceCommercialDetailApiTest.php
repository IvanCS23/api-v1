<?php

use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;

test('el detalle comercial autorizado incluye items y excluye campos PAC internos', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'notes' => 'Snapshot comercial',
    ]);
    $invoice->forceFill([
        'pac_external_id' => 'pac-id-interno',
        'cfdi_uuid' => 'AAAAAAAA-BBBB-4CCC-8DDD-EEEEEEEEEEEE',
    ])->save();
    $item = InvoiceItem::factory()->create([
        'company_id' => $company->id,
        'invoice_id' => $invoice->id,
        'description' => 'Concepto persistido',
    ]);

    $response = $this->actingAs($user, 'api')->getJson("/api/invoices/{$invoice->id}");

    $response->assertOk()
        ->assertJsonPath('id', $invoice->id)
        ->assertJsonPath('notes', 'Snapshot comercial')
        ->assertJsonPath('items.0.id', $item->id)
        ->assertJsonPath('items.0.description', 'Concepto persistido')
        ->assertJsonMissingPath('pac_external_id')
        ->assertJsonMissingPath('cfdi_uuid')
        ->assertJsonMissingPath('pac_status');
});

test('el detalle comercial mantiene aislamiento tenant', function () {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $foreignInvoice = Invoice::factory()->create(['company_id' => $otherCompany->id]);

    $this->actingAs($user, 'api')->getJson("/api/invoices/{$foreignInvoice->id}")
        ->assertNotFound();
});

test('sólo notes se actualiza en estados editables y la respuesta evita un refetch', function (InvoiceStatus $status) {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => $status]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);

    $this->actingAs($user, 'api')->putJson("/api/invoices/{$invoice->id}", [
        'notes' => 'Nota actualizada',
        'status' => InvoiceStatus::Cancelled->value,
        'folio' => 'FOLIO-NO-PERMITIDO',
    ])->assertOk()
        ->assertJsonPath('notes', 'Nota actualizada')
        ->assertJsonPath('status', $status->value)
        ->assertJsonCount(1, 'items');

    expect($invoice->fresh()->folio)->toBe($invoice->folio);
})->with([InvoiceStatus::Draft, InvoiceStatus::Ready]);

test('issued y cancelled bloquean update y delete comercial', function (InvoiceStatus $status) {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => $status]);

    $this->actingAs($user, 'api')->putJson("/api/invoices/{$invoice->id}", ['notes' => 'No permitido'])
        ->assertUnprocessable();
    $this->actingAs($user, 'api')->deleteJson("/api/invoices/{$invoice->id}")
        ->assertUnprocessable();

    expect($invoice->fresh())->not->toBeNull();
})->with([InvoiceStatus::Issued, InvoiceStatus::Cancelled]);

test('draft y ready se pueden eliminar pero ready sólo se alcanza desde draft', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $draft = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Draft]);

    $this->actingAs($user, 'api')->postJson("/api/invoices/{$draft->id}/ready")
        ->assertOk()->assertJsonPath('status', InvoiceStatus::Ready->value);
    $this->actingAs($user, 'api')->postJson("/api/invoices/{$draft->id}/ready")
        ->assertUnprocessable();
    $this->actingAs($user, 'api')->deleteJson("/api/invoices/{$draft->id}")
        ->assertNoContent();
});
