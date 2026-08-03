<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.2.1 — Control de reserva/idempotencia de emisión ante el PAC.
 *
 * Separada de `add_pac_tracking_to_invoices_table` (Fase 6.1, no
 * modificada aquí): esas columnas describen el RESULTADO de un timbrado
 * ya confirmado; estas describen el CICLO DE VIDA del intento de
 * emisión en sí (reserva, reintentos, ambigüedad), necesario para hacer
 * segura la doble ejecución concurrente y la recuperación ante fallos
 * de red. No se ejecuta realmente en este sprint (solo
 * `migrate --pretend`).
 *
 * `pac_idempotency_key` es determinista por Invoice
 * (`erp-invoice:{company_id}:{invoice_id}:v1`, ver IssueInvoiceService)
 * — nunca aleatoria, para que reintentos repitan la misma llave.
 *
 * `pac_issue_status` (sin cast de enum a propósito, igual criterio que
 * `pac_status`/`cancellation_status` de Fase 6.1): null | pending |
 * failed | succeeded | reconciliation_required. No es el `status`
 * interno de Invoice (Draft/Ready/Issued/Cancelled, sin tocar aquí) —
 * es un estado paralelo, específico del ciclo de vida ante el PAC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('pac_idempotency_key')->nullable()->after('pac_last_error');
            $table->string('pac_issue_status', 30)->nullable()->after('pac_idempotency_key');
            $table->timestamp('pac_issue_started_at')->nullable()->after('pac_issue_status');
            $table->unsignedInteger('pac_issue_attempts')->default(0)->after('pac_issue_started_at');
            $table->boolean('pac_reconciliation_required')->default(false)->after('pac_issue_attempts');

            $table->unique(
                ['company_id', 'pac_provider', 'pac_idempotency_key'],
                'erp_invoices_pac_idempotency_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropUnique('erp_invoices_pac_idempotency_unique');

            $table->dropColumn([
                'pac_idempotency_key',
                'pac_issue_status',
                'pac_issue_started_at',
                'pac_issue_attempts',
                'pac_reconciliation_required',
            ]);
        });
    }
};
