<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Etapa A (Fase 1 / Sprint 1.1): agrega company_id nullable a products.
     * No se vuelve NOT NULL ni se reindexan los únicos globales todavía —
     * eso ocurre en una migración posterior, después del backfill.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('company_id')
                ->nullable()
                ->after('id')
                ->constrained('companies')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};
