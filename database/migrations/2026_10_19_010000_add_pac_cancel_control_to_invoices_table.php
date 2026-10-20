<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.19 — Reserva de la solicitud de cancelación ante el PAC.
 *
 * Equivalente a `add_pac_issue_control_to_invoices_table` (Fase 6.2.1)
 * para el DELETE de cancelación. `cancellation_status` refleja los valores
 * remotos de Facturapi y no puede representar "el DELETE salió y no se
 * conoce el resultado"; estas columnas describen el ciclo de vida de la
 * SOLICITUD local, no el estado fiscal.
 *
 * `pac_cancel_request_status` (sin cast de enum, mismo criterio que
 * `pac_issue_status`): null | in_flight | completed | failed |
 * reconciliation_required. Todas nullable y sin backfill: las facturas
 * existentes quedan en null (sin solicitud reservada).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('pac_cancel_request_status', 30)->nullable()->after('pac_reconciliation_required');
            $table->timestamp('pac_cancel_started_at')->nullable()->after('pac_cancel_request_status');
            $table->unsignedInteger('pac_cancel_attempts')->nullable()->after('pac_cancel_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn([
                'pac_cancel_request_status',
                'pac_cancel_started_at',
                'pac_cancel_attempts',
            ]);
        });
    }
};
