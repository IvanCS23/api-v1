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
     * ejecutado y que employes.company_id no tenga ningún NULL, o esta
     * migración fallará al intentar la conversión a NOT NULL.
     *
     * No se toca employes_no_empleado_index (índice simple preexistente,
     * ajeno a este trabajo de multiempresa).
     */
    public function up(): void
    {
        Schema::table('employes', function (Blueprint $table): void {
            $table->dropUnique(['email']);
            $table->dropUnique(['curp']);
            $table->dropUnique(['rfc']);
            $table->dropUnique(['clave_bancaria']);
            $table->dropUnique(['no_empleado']);
            $table->dropUnique(['seguro_social']);
        });

        Schema::table('employes', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->nullable(false)->change();
        });

        Schema::table('employes', function (Blueprint $table): void {
            $table->unique(['company_id', 'email']);
            $table->unique(['company_id', 'curp']);
            $table->unique(['company_id', 'rfc']);
            $table->unique(['company_id', 'clave_bancaria']);
            $table->unique(['company_id', 'no_empleado']);
            $table->unique(['company_id', 'seguro_social']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * RIESGO DE ROLLBACK (documentado, no resuelto artificialmente): igual
     * razonamiento que en clients/products, multiplicado por las 6
     * columnas (`email`, `curp`, `rfc`, `clave_bancaria`, `no_empleado`,
     * `seguro_social`). Basta que UNA sola de ellas tenga un valor
     * repetido entre dos empresas distintas (válido y esperado bajo el
     * esquema único-por-empresa) para que el `$table->unique(...)`
     * correspondiente falle al restaurar el índice global, dejando el
     * rollback a medias. No se agregó fusión/renombrado automático de
     * duplicados. Revertir con más de una empresa activa exige revisar
     * las 6 columnas y resolver duplicados a mano primero.
     */
    public function down(): void
    {
        Schema::table('employes', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'email']);
            $table->dropUnique(['company_id', 'curp']);
            $table->dropUnique(['company_id', 'rfc']);
            $table->dropUnique(['company_id', 'clave_bancaria']);
            $table->dropUnique(['company_id', 'no_empleado']);
            $table->dropUnique(['company_id', 'seguro_social']);
        });

        Schema::table('employes', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->nullable()->change();
        });

        Schema::table('employes', function (Blueprint $table): void {
            $table->unique('email');
            $table->unique('curp');
            $table->unique('rfc');
            $table->unique('clave_bancaria');
            $table->unique('no_empleado');
            $table->unique('seguro_social');
        });
    }
};
