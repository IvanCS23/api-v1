<?php

use App\Enums\ProductType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Aditiva y segura: VARCHAR simple con default a nivel de columna
     * ('product'), no ENUM nativo de MySQL (agregar un valor nuevo a un
     * ENUM de MySQL requiere otra migración de esquema; con VARCHAR +
     * enum de PHP como fuente de verdad — ver App\Enums\ProductType —
     * agregar un caso nuevo es un cambio de código, no de base de datos).
     * MySQL aplica el default a las filas existentes automáticamente al
     * agregar la columna, así que los productos ya creados quedan como
     * 'product' sin necesidad de un backfill aparte.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('type', 20)->default(ProductType::Product->value)->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('type');
        });
    }
};
