<?php

use App\Enums\SaleStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Núcleo comercial (Fase 2), completamente desacoplado de Facturapi:
     * ninguna columna de folio fiscal/UUID/timbrado vive aquí, eso es
     * Fase 3. `folio` es un consecutivo interno por empresa (formato
     * VTA-00000001, ver App\Services\Sales\SaleNumberGenerator), sin
     * relación con el folio fiscal del SAT.
     */
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('folio');
            $table->string('status', 20)->default(SaleStatus::Draft->value);
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
        Schema::dropIfExists('sales');
    }
};
