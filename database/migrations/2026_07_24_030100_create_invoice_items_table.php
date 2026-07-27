<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot fiscal por línea (Fase 5 — ver ERP_ARCHITECTURE_PLAN.md
 * §12.1): `product_id`/`tax_rate_id` se conservan nullable con
 * `nullOnDelete` solo para trazabilidad histórica — nunca como fuente de
 * verdad. Los valores fiscales reales de la línea ya facturada viven en
 * las columnas propias (`product_*`, `tax_*`), copiadas una sola vez al
 * convertir y congeladas para siempre, sin depender de joins futuros
 * hacia `products`/`tax_rates`.
 *
 * Limitación conocida heredada de `sale_items`/`quote_items`: una sola
 * tasa de impuesto por línea (sin soportar traslado + retención
 * simultáneos). Documentada, no resuelta en esta fase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->nullOnDelete();

            $table->string('description');
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('subtotal', 14, 2);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2);

            // Snapshot fiscal del producto al momento de facturar.
            $table->string('product_clave_producto', 8)->nullable();
            $table->string('product_clave_unidad')->nullable();
            $table->string('product_type', 20)->nullable();

            // Snapshot fiscal de la tasa de impuesto al momento de facturar.
            $table->string('tax_code', 3)->nullable();
            $table->string('tax_name')->nullable();
            $table->decimal('tax_rate_value', 8, 6)->nullable();
            $table->string('tax_type', 20)->nullable();
            $table->string('tax_factor_type', 20)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
