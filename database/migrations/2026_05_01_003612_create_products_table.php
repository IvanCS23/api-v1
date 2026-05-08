<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('no_identificacion')->nullable()->unique();
            $table->string('descripcion');
            $table->decimal('precio_unitario', 10, 2);
            $table->string('cuenta_predial')->nullable();
            $table->string('clave_producto', 8)->unique();
            $table->string('clave_unidad')->nullable();
            $table->string('objeto_imp')->nullable();
            $table->string('no_pedimento')->nullable();
            $table->string('impuesto_local')->nullable();
            $table->decimal('iva', 5, 2)->default(16);
            $table->decimal('iva_retenido', 5, 2)->nullable();
            $table->decimal('ieps', 5, 2)->nullable();
            $table->decimal('isr', 5, 2)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
