<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.27 — Identidad de organización por factura.
 *
 * - pac_organization_id: el `organization` que Facturapi devuelve en la
 *   Invoice. Se verifica antes de operar (cancelar, reconciliar,
 *   descargar): una factura de otra organización da un error claro en vez
 *   de un 404 ambiguo. Las facturas existentes se llenan con
 *   `billing:backfill-pac-organization` desde la respuesta ya guardada.
 * - pac_organization_archived_at / pac_organization_archive_reason:
 *   cierre explícito (`billing:archive-inaccessible-invoices`) de una
 *   factura cuya organización ya no es accesible. No toca nada fiscal.
 *
 * Nullable y sin backfill en la migración: la respuesta guardada está
 * cifrada y sólo se lee con el modelo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('pac_organization_id', 64)->nullable()->after('pac_external_id')->index();
            $table->timestamp('pac_organization_archived_at')->nullable()->after('pac_organization_id');
            $table->string('pac_organization_archive_reason')->nullable()->after('pac_organization_archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropIndex(['pac_organization_id']);
            $table->dropColumn(['pac_organization_id', 'pac_organization_archived_at', 'pac_organization_archive_reason']);
        });
    }
};
