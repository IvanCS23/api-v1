<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — QuoteWorkflow::approve() fija approved_at; la conversión a
 * venta (QuoteToSaleConverter, única responsable de Approved→Converted)
 * fija converted_at junto con converted_sale_id. Ambas columnas nacen
 * NULL y ninguna es editable vía el endpoint genérico de update (ver
 * UpdateQuoteRequest).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->timestamp('approved_at')->nullable()->after('status');
            $table->timestamp('converted_at')->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropColumn(['approved_at', 'converted_at']);
        });
    }
};
