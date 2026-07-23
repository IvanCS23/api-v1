<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Misma estructura que `sale_items` (Fase 2): `company_id` propio
     * (BelongsToCompany/CompanyScope directo, no solo heredado vía
     * `quote_id`), `product_id`/`tax_rate_id` como snapshots
     * (nullOnDelete).
     */
    public function up(): void
    {
        Schema::create('quote_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('quote_id')->constrained('quotes')->cascadeOnDelete();
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

            $table->index(['company_id', 'quote_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quote_items');
    }
};
