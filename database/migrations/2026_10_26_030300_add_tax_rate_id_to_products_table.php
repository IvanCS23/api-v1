<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.26 — El IVA del producto referencia una tasa de tax_rates.
 *
 * `products.iva` es un decimal: no puede representar "exento", que es un
 * tratamiento fiscal distinto de la tasa 0 %. Desde la pantalla, "Exento"
 * llegaba como null y la columna guardaba su default (16). `tax_rate_id`
 * pasa a ser la fuente (IVA 16 %, IVA 0 % o IVA Exento); `iva` queda como
 * valor derivado para compatibilidad.
 *
 * Backfill fiel a lo guardado: iva 16 → tasa 16 %, iva 0 → tasa 0 %; otro
 * valor (p. ej. 8, que no existe en tax_rates) queda sin tasa. Ningún
 * producto existente pudo haberse guardado como exento, así que no se
 * infiere. No corrige objetos de impuesto incoherentes: eso lo reporta
 * sat:audit-catalog-data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('tax_rate_id')->nullable()->after('objeto_imp')->constrained('tax_rates');
        });

        foreach (['16.00' => 0.16, '0.00' => 0.0] as $iva => $rate) {
            $taxRateId = DB::table('tax_rates')
                ->where('code', '002')
                ->where('tax_type', 'traslado')
                ->where('factor_type', 'tasa')
                ->where('rate', $rate)
                ->value('id');

            if ($taxRateId !== null) {
                DB::table('products')->where('iva', $iva)->update(['tax_rate_id' => $taxRateId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tax_rate_id');
        });
    }
};
