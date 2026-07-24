<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — SaleWorkflow necesita registrar cuándo ocurrió cada
 * transición terminal. Ambas columnas nacen NULL y las fija
 * exclusivamente SaleWorkflow (confirm()/cancel()) — nunca el endpoint
 * genérico de update (ver UpdateSaleRequest, que las excluye a propósito).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->timestamp('confirmed_at')->nullable()->after('status');
            $table->timestamp('cancelled_at')->nullable()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropColumn(['confirmed_at', 'cancelled_at']);
        });
    }
};
