<?php

use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Smoke test de la migración `add_pac_issue_control_to_invoices_table`
 * (Fase 6.2.1), mismo criterio que InvoicePacTrackingTest para la
 * migración de Fase 6.1: no se ejecuta manualmente en este sprint, solo
 * vía RefreshDatabase al arrancar la suite y `migrate --pretend` como
 * prueba de humo del comando en sí.
 */
test('la migración de control de reserva/idempotencia es válida: --pretend no falla y las columnas/índice quedan bien definidos', function () {
    $exitCode = Artisan::call('migrate', ['--pretend' => true]);
    expect($exitCode)->toBe(0);

    expect(Schema::hasColumns('invoices', [
        'pac_idempotency_key',
        'pac_issue_status',
        'pac_issue_started_at',
        'pac_issue_attempts',
        'pac_reconciliation_required',
    ]))->toBeTrue();

    $indexes = collect(Schema::getIndexes('invoices'))->pluck('name');
    expect($indexes)->toContain('erp_invoices_pac_idempotency_unique');
});

test('pac_issue_attempts default es 0 y pac_reconciliation_required default es false para una Invoice nueva', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);

    expect($invoice->fresh()->pac_issue_attempts)->toBe(0)
        ->and($invoice->fresh()->pac_reconciliation_required)->toBeFalse()
        ->and($invoice->fresh()->pac_idempotency_key)->toBeNull()
        ->and($invoice->fresh()->pac_issue_status)->toBeNull();
});

test('las columnas de control de reserva no son asignables masivamente (no fillable)', function () {
    $company = Company::factory()->create();
    // fresh(): el default `0`/`false` de la columna a nivel de esquema
    // no se refleja en la instancia recién creada en memoria hasta
    // releerla — sin esto, pac_issue_attempts sería null (no 0) antes de
    // siquiera intentar el fill() bloqueado.
    $invoice = Invoice::factory()->create(['company_id' => $company->id])->fresh();

    $invoice->fill([
        'pac_idempotency_key' => 'erp-invoice:1:1:v1',
        'pac_issue_status' => 'succeeded',
        'pac_issue_attempts' => 99,
        'pac_reconciliation_required' => true,
    ]);

    expect($invoice->pac_idempotency_key)->toBeNull()
        ->and($invoice->pac_issue_status)->toBeNull()
        ->and($invoice->pac_issue_attempts)->toBe(0)
        ->and($invoice->pac_reconciliation_required)->toBeFalse();
});

test('la unicidad (company_id, pac_provider, pac_idempotency_key) impide dos filas con la misma llave para la misma empresa', function () {
    $company = Company::factory()->create();
    $key = "erp-invoice:{$company->id}:duplicate-test:v1";

    $first = Invoice::factory()->create(['company_id' => $company->id]);
    $first->forceFill(['pac_provider' => 'facturapi', 'pac_idempotency_key' => $key])->save();

    $second = Invoice::factory()->create(['company_id' => $company->id]);

    expect(fn () => $second->forceFill(['pac_provider' => 'facturapi', 'pac_idempotency_key' => $key])->save())
        ->toThrow(\Illuminate\Database\QueryException::class);
});
