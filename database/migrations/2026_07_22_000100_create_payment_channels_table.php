<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `payment_channels`: catálogo interno de canales/instrumentos de
     * cobro que ya usa (o usará) el ERP para registrar cómo se cobró una
     * venta (efectivo, transferencia, tarjeta de crédito/débito, cheque).
     *
     * Deliberadamente NO se llama `payment_methods` para no confundirlo
     * ni conectarlo por accidente con conceptos fiscales del SAT/CFDI,
     * que son catálogos distintos y **todavía no existen**:
     * - "Método de pago" SAT/CFDI = PUE | PPD (c_MetodoPago).
     * - "Forma de pago" SAT/CFDI = efectivo, cheque, transferencia,
     *   tarjetas, etc. pero con los códigos oficiales del SAT
     *   (c_FormaPago, ej. "01" Efectivo, "03" Transferencia).
     * Ambos se modelarán como catálogos separados cuando se construya la
     * integración con Facturapi (Fase 3) — no aquí, y no todavía.
     *
     * Global (sin company_id): catálogo de referencia del sistema.
     */
    public function up(): void
    {
        Schema::create('payment_channels', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->boolean('requires_bank')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_channels');
    }
};
