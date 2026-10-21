<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.22 — Inicio de la descarga del acuse de cancelación.
 *
 * DownloadCancellationReceiptService reserva la descarga con
 * `cancellation_receipt_status=pending` antes del HTTP. Si el proceso
 * muere a mitad, la reserva quedaba `pending` para siempre (mismo problema
 * que los artifacts CFDI antes de la Fase 6.21).
 * `cancellation_receipt_started_at` permite retomar una reserva más vieja
 * que `billing.cancellation_receipt.pending_minutes`.
 *
 * Nullable y sin backfill: un `pending` previo sin fecha se trata como
 * abandonado y puede retomarse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->timestamp('cancellation_receipt_started_at')->nullable()->after('cancellation_receipt_status');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('cancellation_receipt_started_at');
        });
    }
};
