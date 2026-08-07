<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.6: metadata del XML/PDF del acuse de cancelacion. Son artifacts
 * adicionales y no reemplazan ni modifican las columnas cfdi_* de Fase
 * 6.3. Los bytes viven en el disk privado; la DB solo guarda rutas
 * relativas, hashes, tamanos y estado operacional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('cancellation_receipt_xml_path')->nullable()->after('cfdi_artifacts_status');
            $table->string('cancellation_receipt_pdf_path')->nullable()->after('cancellation_receipt_xml_path');
            $table->char('cancellation_receipt_xml_sha256', 64)->nullable()->after('cancellation_receipt_pdf_path');
            $table->char('cancellation_receipt_pdf_sha256', 64)->nullable()->after('cancellation_receipt_xml_sha256');
            $table->unsignedBigInteger('cancellation_receipt_xml_size')->nullable()->after('cancellation_receipt_pdf_sha256');
            $table->unsignedBigInteger('cancellation_receipt_pdf_size')->nullable()->after('cancellation_receipt_xml_size');
            $table->timestamp('cancellation_receipt_downloaded_at')->nullable()->after('cancellation_receipt_pdf_size');
            $table->string('cancellation_receipt_status', 30)->nullable()->after('cancellation_receipt_downloaded_at');
            $table->text('cancellation_receipt_last_error')->nullable()->after('cancellation_receipt_status');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn([
                'cancellation_receipt_xml_path',
                'cancellation_receipt_pdf_path',
                'cancellation_receipt_xml_sha256',
                'cancellation_receipt_pdf_sha256',
                'cancellation_receipt_xml_size',
                'cancellation_receipt_pdf_size',
                'cancellation_receipt_downloaded_at',
                'cancellation_receipt_status',
                'cancellation_receipt_last_error',
            ]);
        });
    }
};
