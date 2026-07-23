<?php

use App\Enums\QuoteStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Reutiliza la estructura de `sales` (Fase 2). `converted_sale_id`
     * registra la venta creada al convertir (QuoteToSaleConverter) — la
     * cotización original nunca se borra ni se modifica más allá de
     * `status`/`converted_sale_id`. `folio` es un consecutivo interno
     * por empresa (formato COT-00000001), sin relación con Facturapi.
     */
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('converted_sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->string('folio');
            $table->string('status', 20)->default(QuoteStatus::Draft->value);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->string('currency', 3)->default('MXN');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'folio']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'client_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
