<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.34 (cálculo estilo SAT): importe exacto del concepto
 * (cantidad × valor unitario) con 6 decimales, como el Importe del CFDI.
 * `subtotal` sigue con 2 decimales para mostrarlo. Las líneas anteriores
 * quedan en null y usan su `subtotal` (no se recalculan; una venta se
 * recalcula al facturar).
 */
return new class extends Migration
{
    private const TABLES = ['quote_items', 'sale_items', 'invoice_items'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->decimal('subtotal_exact', 18, 6)->nullable()->after('subtotal');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('subtotal_exact');
            });
        }
    }
};
