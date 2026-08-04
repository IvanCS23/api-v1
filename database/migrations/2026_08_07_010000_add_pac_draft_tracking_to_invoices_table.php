<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.2.4 — Tracking local del BORRADOR remoto en Facturapi
 * (`status: "draft"`), separado por completo del tracking de emisión ya
 * confirmada (`pac_external_id`/`cfdi_uuid`/`stamped_at`, Fase 6.1) y del
 * ciclo de vida del intento de emisión (`pac_issue_*`, Fase 6.2.1).
 *
 * Un draft es un recurso REAL en Facturapi (se persiste ahí, no se
 * timbra, no tiene UUID/SAT) — completamente distinto de un CFDI
 * emitido. Mezclarlo con las columnas de emisión final habría hecho
 * imposible distinguir "existe un borrador" de "existe una factura
 * timbrada", exactamente el tipo de confusión que esta fase pide evitar
 * arquitectónicamente.
 *
 * `pac_draft_response`: longText + `encrypted:array` + `hidden` en el
 * modelo (mismo criterio que `pac_response`, Fase 6.1).
 *
 * Índices: mismo patrón que `erp_invoices_pac_provider_external_unique`/
 * `erp_invoices_pac_idempotency_unique` (Fase 6.1/6.2.1), aplicado ahora
 * al draft — permite que ambos (emisión final y draft) coexistan sin
 * colisionar entre sí ni con otra empresa.
 *
 * No se ejecuta realmente en este sprint (solo `migrate --pretend`). No
 * modifica ninguna migración existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('pac_draft_external_id')->nullable()->after('payment_method');
            $table->string('pac_draft_status', 30)->nullable()->after('pac_draft_external_id');
            $table->boolean('pac_draft_ready_to_stamp')->nullable()->after('pac_draft_status');
            $table->string('pac_draft_idempotency_key')->nullable()->after('pac_draft_ready_to_stamp');
            $table->timestamp('pac_draft_created_at')->nullable()->after('pac_draft_idempotency_key');
            $table->timestamp('pac_draft_last_sync_at')->nullable()->after('pac_draft_created_at');
            $table->longText('pac_draft_response')->nullable()->after('pac_draft_last_sync_at');

            $table->unique(
                ['company_id', 'pac_provider', 'pac_draft_external_id'],
                'erp_invoices_pac_draft_external_unique',
            );
            $table->unique(
                ['company_id', 'pac_provider', 'pac_draft_idempotency_key'],
                'erp_invoices_pac_draft_idempotency_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropUnique('erp_invoices_pac_draft_external_unique');
            $table->dropUnique('erp_invoices_pac_draft_idempotency_unique');

            $table->dropColumn([
                'pac_draft_external_id',
                'pac_draft_status',
                'pac_draft_ready_to_stamp',
                'pac_draft_idempotency_key',
                'pac_draft_created_at',
                'pac_draft_last_sync_at',
                'pac_draft_response',
            ]);
        });
    }
};
