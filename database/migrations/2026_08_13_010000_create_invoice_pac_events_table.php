<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.7: bitacora fiscal PAC append-only. No reconstruye historia previa:
 * las Invoices existentes comienzan a registrar eventos desde esta migracion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_pac_events', function (Blueprint $table) {
    $table->id();

    $table->unsignedBigInteger('company_id');
    $table->unsignedBigInteger('invoice_id');

    $table->string('event_type', 80);

    $table->string('pac_provider', 30)->nullable();
    $table->string('pac_external_id', 255)->nullable();
    $table->char('cfdi_uuid', 36)->nullable();

    $table->string('pac_status', 30)->nullable();
    $table->string('cancellation_status', 30)->nullable();
    $table->string('pac_issue_status', 30)->nullable();

    $table->string('pac_code', 100)->nullable();

    $table->timestamp('occurred_at')->useCurrent();

    $table->longText('context')->nullable();

    $table->timestamp('created_at')->useCurrent();

            $table->foreign('company_id', 'erp_invoice_pac_events_company_fk')
                ->references('id')
                ->on('companies')
                ->restrictOnDelete();

            $table->foreign('invoice_id', 'erp_invoice_pac_events_invoice_fk')
                ->references('id')
                ->on('invoices')
                ->restrictOnDelete();

            $table->index(
                ['company_id', 'invoice_id'],
                'erp_invoice_pac_events_company_invoice_index',
            );
            $table->index(
                ['company_id', 'event_type'],
                'erp_invoice_pac_events_company_type_index',
            );
            $table->index(
                ['company_id', 'occurred_at'],
                'erp_invoice_pac_events_company_occurred_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_pac_events');
    }
};
