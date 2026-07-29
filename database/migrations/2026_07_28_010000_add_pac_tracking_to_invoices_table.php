<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.1 — Infraestructura PAC (sin timbrado real todavía).
 *
 * Columnas de tracking del proveedor de certificación, separadas del
 * snapshot fiscal ya congelado en Fase 5 — estas SÍ se actualizan
 * después de crear la Invoice (una vez que exista el flujo real de
 * timbrado, fuera de alcance de este sprint), a diferencia de las
 * columnas `client_*`/`product_*` que nunca cambian tras la conversión.
 *
 * NO se modifica ninguna migración ya ejecutada de Fase 5
 * (`create_invoices_table`, `create_invoice_items_table`,
 * `rename_invoices_to_legacy_invoices`) — esta es una migración nueva e
 * independiente. NO se ejecuta realmente en este sprint (solo
 * `migrate --pretend`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('pac_provider', 30)->nullable()->after('cancelled_at');
            $table->string('pac_external_id')->nullable()->after('pac_provider');
            $table->uuid('cfdi_uuid')->nullable()->after('pac_external_id');
            $table->string('pac_status', 30)->nullable()->after('cfdi_uuid');
            $table->string('cancellation_status', 30)->nullable()->after('pac_status');
            $table->timestamp('stamped_at')->nullable()->after('cancellation_status');
            $table->timestamp('last_pac_sync_at')->nullable()->after('stamped_at');
            $table->longText('pac_response')->nullable()->after('last_pac_sync_at');
            $table->text('pac_last_error')->nullable()->after('pac_response');

            $table->unique(
                ['company_id', 'pac_provider', 'pac_external_id'],
                'erp_invoices_pac_provider_external_unique',
            );

            $table->unique(
                ['company_id', 'cfdi_uuid'],
                'erp_invoices_pac_cfdi_uuid_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropUnique('erp_invoices_pac_provider_external_unique');
            $table->dropUnique('erp_invoices_pac_cfdi_uuid_unique');

            $table->dropColumn([
                'pac_provider',
                'pac_external_id',
                'cfdi_uuid',
                'pac_status',
                'cancellation_status',
                'stamped_at',
                'last_pac_sync_at',
                'pac_response',
                'pac_last_error',
            ]);
        });
    }
};
