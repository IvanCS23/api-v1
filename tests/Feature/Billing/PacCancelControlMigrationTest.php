<?php

use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

const PHASE_619_CANCEL_COLUMNS = ['pac_cancel_request_status', 'pac_cancel_started_at', 'pac_cancel_attempts'];

test('la migración de reserva de cancelación agrega columnas nullable sin backfill', function () {
    expect(Artisan::call('migrate', ['--pretend' => true]))->toBe(0)
        ->and(Schema::hasColumns('invoices', PHASE_619_CANCEL_COLUMNS))->toBeTrue();

    $columns = collect(Schema::getColumns('invoices'))->keyBy('name');

    foreach (PHASE_619_CANCEL_COLUMNS as $column) {
        expect($columns[$column]['nullable'])->toBeTrue()
            ->and($columns[$column]['default'])->toBeNull();
    }

    $invoice = Invoice::factory()->create(['company_id' => Company::factory()->create()->id])->fresh();

    expect($invoice->pac_cancel_request_status)->toBeNull()
        ->and($invoice->pac_cancel_started_at)->toBeNull()
        ->and($invoice->pac_cancel_attempts)->toBeNull();
});

test('las columnas de reserva de cancelación no son asignables masivamente', function () {
    expect(array_intersect(PHASE_619_CANCEL_COLUMNS, (new Invoice)->getFillable()))->toBe([]);
});

test('down() de la migración es reversible', function () {
    $migration = require database_path('migrations/2026_10_19_010000_add_pac_cancel_control_to_invoices_table.php');

    $migration->down();
    expect(Schema::hasColumn('invoices', 'pac_cancel_request_status'))->toBeFalse()
        ->and(Schema::hasColumn('invoices', 'pac_cancel_started_at'))->toBeFalse()
        ->and(Schema::hasColumn('invoices', 'pac_cancel_attempts'))->toBeFalse();

    $migration->up();
    expect(Schema::hasColumns('invoices', PHASE_619_CANCEL_COLUMNS))->toBeTrue();
});
