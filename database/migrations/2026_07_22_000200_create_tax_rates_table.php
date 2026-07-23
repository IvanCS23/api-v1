<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Catálogo global de tasas de impuestos (no company_id: las tasas de
     * IVA/ISR/IEPS son nacionales, no varían por empresa; si en el
     * futuro una empresa necesita una tasa custom, se evaluará agregar
     * company_id nullable, no antes). `rate` es decimal, nunca float,
     * para no perder precisión en cálculos fiscales. `tax_type` y
     * `factor_type` son VARCHAR respaldados por enums de PHP (ver
     * App\Enums\TaxType y App\Enums\TaxFactorType), no ENUM nativo de
     * MySQL. `code` es una referencia opcional al catálogo `c_Impuesto`
     * del SAT (001 ISR, 002 IVA, 003 IEPS) — no calcula ni timbra nada
     * todavía, es solo el catálogo.
     */
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 3)->nullable();
            $table->string('name');
            $table->decimal('rate', 8, 6);
            $table->string('tax_type', 20);
            $table->string('factor_type', 20);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};
