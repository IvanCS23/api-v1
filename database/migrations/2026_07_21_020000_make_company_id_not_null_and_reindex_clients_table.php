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
     * ejecutado y que clients.company_id no tenga ningún NULL, o esta
     * migración fallará al intentar la conversión a NOT NULL.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropUnique(['email']);
            $table->dropUnique(['rfc']);
        });

        Schema::table('clients', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->nullable(false)->change();
        });

        Schema::table('clients', function (Blueprint $table): void {
            $table->unique(['company_id', 'email']);
            $table->unique(['company_id', 'rfc']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * RIESGO DE ROLLBACK (documentado, no resuelto artificialmente):
     * este down() intenta restaurar los índices únicos GLOBALES
     * originales (`email`, `rfc` únicos en toda la tabla, sin importar
     * la empresa). Si para cuando se ejecuta este rollback ya existen
     * dos o más empresas con el mismo email o el mismo RFC en `clients`
     * (perfectamente válido y esperado bajo el esquema NOT NULL +
     * únicos-por-empresa que esta migración introdujo), el
     * `$table->unique('email')`/`$table->unique('rfc')` de abajo
     * FALLARÁ con un error de índice duplicado, y el rollback se
     * detendrá a mitad de camino (con `company_id` ya vuelto nullable,
     * pero sin los índices únicos globales restaurados).
     *
     * No se agregó lógica para fusionar/renombrar registros duplicados
     * automáticamente: hacerlo perdería datos de negocio reales (dos
     * clientes de dos empresas distintas que legítimamente comparten
     * RFC/email) sin que el operador lo decida explícitamente. Si se
     * necesita revertir esta migración en un entorno con más de una
     * empresa activa, hay que resolver manualmente los duplicados
     * (renombrar o eliminar) antes de correr `migrate:rollback`.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'email']);
            $table->dropUnique(['company_id', 'rfc']);
        });

        Schema::table('clients', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->nullable()->change();
        });

        Schema::table('clients', function (Blueprint $table): void {
            $table->unique('email');
            $table->unique('rfc');
        });
    }
};
