<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.3 — Tracking local de los ARCHIVOS fiscales (XML CFDI, PDF
 * representación impresa) de una Invoice ya timbrada
 * (`cfdi_uuid`/`pac_status=valid`, Fase 6.2.5/6.2.6). Completamente
 * separado del tracking de emisión (`pac_external_id`/`cfdi_uuid`/
 * `stamped_at`, Fase 6.1) y del de borrador (`pac_draft_*`, Fase 6.2.4):
 * un CFDI puede estar timbrado sin que sus archivos se hayan descargado
 * todavía — ambos conceptos deben poder variar de forma independiente.
 *
 * Deliberadamente NO se guarda el XML/PDF en la base de datos (ni
 * longText ni blob) — solo la RUTA relativa dentro del disk privado de
 * Storage (`cfdi_xml_path`/`cfdi_pdf_path`) y metadatos de integridad
 * (`cfdi_xml_sha256`/`cfdi_pdf_sha256`, tamaños en bytes). Los archivos
 * en sí viven en `storage/app/private/cfdi/{company_id}/{invoice_id}/`
 * (disk `local`, ya privado por defecto en este esqueleto de Laravel —
 * ver reporte de entrega de esta fase para la justificación de reusar
 * ese disk en vez de crear uno nuevo).
 *
 * `cfdi_artifacts_status`: mismo patrón que `pac_issue_status`/
 * `pac_draft_status` (string corto, no enum de base de datos) —
 * `null` (nunca se intentó) | `pending` (reserva corta antes del HTTP,
 * mismo patrón de concurrencia que Fase 6.2.1/6.2.5) | `stored` (XML+PDF
 * validados y persistidos) | `failed` (error definitivo del PAC) |
 * `reconciliation_required` (error ambiguo o archivos locales faltantes
 * pese a que la DB dice `stored`).
 *
 * `cfdi_artifacts_last_error`: mismo criterio que `pac_last_error` —
 * mensaje saneado (nunca Authorization/API key/contenido fiscal
 * completo), nunca el XML/PDF crudo.
 *
 * No se ejecuta realmente en este sprint (solo `migrate --pretend`). No
 * modifica ninguna migración existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('cfdi_xml_path')->nullable()->after('pac_draft_response');
            $table->string('cfdi_pdf_path')->nullable()->after('cfdi_xml_path');
            $table->char('cfdi_xml_sha256', 64)->nullable()->after('cfdi_pdf_path');
            $table->char('cfdi_pdf_sha256', 64)->nullable()->after('cfdi_xml_sha256');
            $table->unsignedBigInteger('cfdi_xml_size')->nullable()->after('cfdi_pdf_sha256');
            $table->unsignedBigInteger('cfdi_pdf_size')->nullable()->after('cfdi_xml_size');
            $table->timestamp('cfdi_artifacts_downloaded_at')->nullable()->after('cfdi_pdf_size');
            $table->text('cfdi_artifacts_last_error')->nullable()->after('cfdi_artifacts_downloaded_at');
            $table->string('cfdi_artifacts_status', 30)->nullable()->after('cfdi_artifacts_last_error');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn([
                'cfdi_xml_path',
                'cfdi_pdf_path',
                'cfdi_xml_sha256',
                'cfdi_pdf_sha256',
                'cfdi_xml_size',
                'cfdi_pdf_size',
                'cfdi_artifacts_downloaded_at',
                'cfdi_artifacts_last_error',
                'cfdi_artifacts_status',
            ]);
        });
    }
};
