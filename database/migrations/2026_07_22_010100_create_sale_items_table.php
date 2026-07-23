<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `company_id` propio (no solo heredado vía `sale_id`) porque
     * SaleItem usa BelongsToCompany/CompanyScope directamente, así una
     * consulta directa sobre SaleItem (sin pasar por Sale) también queda
     * aislada por tenant. `product_id`/`tax_rate_id` son snapshots
     * (nullOnDelete): si el producto o la tasa cambian o se borran
     * después, la línea de venta ya registrada no debe mutar.
     */
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->nullOnDelete();
            $table->string('description');
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('subtotal', 14, 2);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'sale_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
