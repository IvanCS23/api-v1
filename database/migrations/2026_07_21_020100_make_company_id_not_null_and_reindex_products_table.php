<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Requiere que el backfill (erp:backfill-company-id) ya se haya
     * ejecutado y que products.company_id no tenga ningún NULL, o esta
     * migración fallará al intentar la conversión a NOT NULL.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique(['clave_producto']);
            $table->dropUnique(['no_identificacion']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->nullable(false)->change();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->unique(['company_id', 'clave_producto']);
            $table->unique(['company_id', 'no_identificacion']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * RIESGO DE ROLLBACK (documentado, no resuelto artificialmente): igual
     * que en clients, restaurar `$table->unique('clave_producto')` /
     * `$table->unique('no_identificacion')` fallará si para entonces dos
     * o más empresas ya registraron el mismo `clave_producto` o
     * `no_identificacion` (algo válido y esperado bajo el esquema
     * único-por-empresa). No se agregó fusión/renombrado automático de
     * duplicados: requeriría decisiones de negocio que no corresponde
     * tomar en una migración. Revertir en un entorno con más de una
     * empresa activa exige resolver los duplicados a mano primero.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'clave_producto']);
            $table->dropUnique(['company_id', 'no_identificacion']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->nullable()->change();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->unique('clave_producto');
            $table->unique('no_identificacion');
        });
    }
};
