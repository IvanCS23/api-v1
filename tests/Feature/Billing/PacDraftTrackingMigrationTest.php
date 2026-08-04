<?php

use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Smoke test de `add_pac_draft_tracking_to_invoices_table` (Fase 6.2.4).
 * No se ejecuta manualmente en este sprint, solo vía RefreshDatabase al
 * arrancar la suite y `migrate --pretend`.
 */
test('la migración de tracking de draft es válida: --pretend no falla y las columnas/índices quedan bien definidos', function () {
    $exitCode = Artisan::call('migrate', ['--pretend' => true]);
    expect($exitCode)->toBe(0);

    expect(Schema::hasColumns('invoices', [
        'pac_draft_external_id',
        'pac_draft_status',
        'pac_draft_ready_to_stamp',
        'pac_draft_idempotency_key',
        'pac_draft_created_at',
        'pac_draft_last_sync_at',
        'pac_draft_response',
    ]))->toBeTrue();

    $indexes = collect(Schema::getIndexes('invoices'))->pluck('name');
    expect($indexes)->toContain('erp_invoices_pac_draft_external_unique')
        ->and($indexes)->toContain('erp_invoices_pac_draft_idempotency_unique');
});

test('las columnas pac_draft_* no son asignables masivamente (no fillable)', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);

    $invoice->fill([
        'pac_draft_external_id' => 'inv_draft_mass_assign',
        'pac_draft_status' => 'draft',
        'pac_draft_ready_to_stamp' => true,
    ]);

    expect($invoice->pac_draft_external_id)->toBeNull()
        ->and($invoice->pac_draft_status)->toBeNull()
        ->and($invoice->pac_draft_ready_to_stamp)->toBeNull();
});

test('pac_draft_response nunca aparece en toArray()/toJson() (hidden) y se guarda cifrado', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    $invoice->forceFill([
        'pac_draft_response' => ['id' => 'inv_secret_draft', 'raw' => 'dato interno del borrador'],
    ])->save();

    $invoice->refresh();

    expect($invoice->toArray())->not->toHaveKey('pac_draft_response');

    $rawValue = DB::table('invoices')->where('id', $invoice->id)->value('pac_draft_response');
    expect($rawValue)->not->toContain('dato interno del borrador');

    expect($invoice->fresh()->pac_draft_response)->toBe(['id' => 'inv_secret_draft', 'raw' => 'dato interno del borrador']);
});

test('pac_draft_ready_to_stamp/created_at/last_sync_at tienen el cast correcto', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    $invoice->forceFill([
        'pac_draft_ready_to_stamp' => true,
        'pac_draft_created_at' => now(),
        'pac_draft_last_sync_at' => now(),
    ])->save();

    $fresh = $invoice->fresh();

    expect($fresh->pac_draft_ready_to_stamp)->toBeBool()
        ->and($fresh->pac_draft_created_at)->toBeInstanceOf(\Carbon\CarbonImmutable::class)
        ->and($fresh->pac_draft_last_sync_at)->toBeInstanceOf(\Carbon\CarbonImmutable::class);
});

test('la unicidad (company_id, pac_provider, pac_draft_external_id) impide dos filas con el mismo draft para la misma empresa', function () {
    $company = Company::factory()->create();

    $first = Invoice::factory()->create(['company_id' => $company->id]);
    $first->forceFill(['pac_provider' => 'facturapi', 'pac_draft_external_id' => 'inv_draft_dup'])->save();

    $second = Invoice::factory()->create(['company_id' => $company->id]);

    expect(fn () => $second->forceFill(['pac_provider' => 'facturapi', 'pac_draft_external_id' => 'inv_draft_dup'])->save())
        ->toThrow(\Illuminate\Database\QueryException::class);
});

test('la unicidad (company_id, pac_provider, pac_draft_idempotency_key) impide dos filas con la misma llave de draft', function () {
    $company = Company::factory()->create();
    $key = "erp-invoice-draft:{$company->id}:duplicate-test:v1";

    $first = Invoice::factory()->create(['company_id' => $company->id]);
    $first->forceFill(['pac_provider' => 'facturapi', 'pac_draft_idempotency_key' => $key])->save();

    $second = Invoice::factory()->create(['company_id' => $company->id]);

    expect(fn () => $second->forceFill(['pac_provider' => 'facturapi', 'pac_draft_idempotency_key' => $key])->save())
        ->toThrow(\Illuminate\Database\QueryException::class);
});
