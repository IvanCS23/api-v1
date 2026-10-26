<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.26 — Catálogo SAT c_ClaveProdServ (≈52 mil claves).
 *
 * Global, no por empresa (como tax_rates): es el catálogo nacional del SAT.
 * Se carga con `php artisan sat:import-catalogs` desde
 * database/data/sat/clave_prod_serv.csv, que a su vez se genera del archivo
 * oficial del Anexo 20 (ver el README de ese directorio). Validar contra
 * esta tabla no depende del PAC: funciona sin red y en las pruebas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sat_product_keys', function (Blueprint $table): void {
            $table->char('code', 8)->primary();
            $table->string('description', 255);
            $table->text('similar_words')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sat_product_keys');
    }
};
