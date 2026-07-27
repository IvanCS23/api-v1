<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 5 — Invoice Domain: dominio interno de facturas, desacoplado de
 * cualquier PAC. Una Invoice siempre nace de exactamente una Sale ya
 * Confirmed y con billing-readiness `ready=true` (ver
 * SaleToInvoiceConverter) — por eso `sale_id` es NOT NULL y único (una
 * Sale nunca puede tener más de una Invoice).
 *
 * Snapshot fiscal del cliente: se copian como columnas propias de
 * `invoices` (prefijo `client_`) los mismos campos fiscales auditados en
 * Fase 4 (`clients.name/rfc/regimen_fiscal/uso_cfdi/codigo_postal` +
 * domicilio). `client_id` se conserva solo como FK de trazabilidad — una
 * Invoice ya emitida nunca debe cambiar de valor porque el cliente
 * corrigió su RFC después (ver ERP_ARCHITECTURE_PLAN.md §12.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('sale_id')->unique()->constrained('sales')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('folio');
            $table->string('status', 20)->default('draft');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->string('currency', 3)->default('MXN');
            $table->text('notes')->nullable();

            // Snapshot fiscal del cliente al momento de facturar.
            $table->string('client_name');
            $table->string('client_rfc', 13);
            $table->string('client_regimen_fiscal', 3);
            $table->string('client_uso_cfdi', 3);
            $table->string('client_codigo_postal', 10);
            $table->string('client_calle')->nullable();
            $table->string('client_no_exterior')->nullable();
            $table->string('client_no_interior')->nullable();
            $table->string('client_colonia')->nullable();
            $table->string('client_localidad')->nullable();
            $table->string('client_municipio')->nullable();
            $table->string('client_estado')->nullable();
            $table->string('client_pais')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'folio']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
