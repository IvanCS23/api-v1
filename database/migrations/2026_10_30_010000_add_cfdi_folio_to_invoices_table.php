<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.28 — Serie y folio con los que quedó timbrado el CFDI.
 *
 * Los llena el modelo Invoice desde `pac_response` (series/folio_number
 * de Facturapi) cada vez que se guarda una respuesta del PAC. Desde la
 * 6.28 coinciden con el folio interno (FAC-00000019 → FAC / 19); las
 * facturas anteriores conservan lo que asignó Facturapi (serie "F").
 *
 * Nullable y sin backfill en la migración: la respuesta guardada está
 * cifrada y sólo se lee con el modelo (`billing:backfill-cfdi-folio`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('cfdi_series', 25)->nullable()->after('cfdi_uuid');
            $table->unsignedInteger('cfdi_folio_number')->nullable()->after('cfdi_series');
            $table->index(['company_id', 'cfdi_folio_number']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'cfdi_folio_number']);
            $table->dropColumn(['cfdi_series', 'cfdi_folio_number']);
        });
    }
};
