<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.21 — Inicio de la descarga de artifacts CFDI.
 *
 * DownloadInvoiceArtifactsService reserva la descarga con
 * `cfdi_artifacts_status=pending` antes del HTTP. Si el proceso muere a
 * mitad (p. ej. un worker de cola), la reserva quedaba `pending` para
 * siempre. `cfdi_artifacts_started_at` permite retomar una reserva más
 * vieja que `billing.artifacts.pending_minutes`.
 *
 * Nullable y sin backfill: un `pending` previo sin fecha se trata como
 * abandonado y puede retomarse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->timestamp('cfdi_artifacts_started_at')->nullable()->after('cfdi_artifacts_status');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('cfdi_artifacts_started_at');
        });
    }
};
