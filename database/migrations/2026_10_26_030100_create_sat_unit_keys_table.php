<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.26 — Catálogo SAT c_ClaveUnidad (≈2.4 mil claves de 2 o 3
 * caracteres). Global y cargado igual que sat_product_keys
 * (`php artisan sat:import-catalogs`, desde clave_unidad.csv).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sat_unit_keys', function (Blueprint $table): void {
            $table->string('code', 3)->primary();
            $table->string('name', 255);
            $table->string('symbol', 50)->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sat_unit_keys');
    }
};
