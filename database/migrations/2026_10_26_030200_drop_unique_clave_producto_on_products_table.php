<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.26 — La clave SAT de producto deja de ser única por empresa.
 *
 * `clave_producto` es la clasificación c_ClaveProdServ, no un identificador:
 * dos productos distintos de la misma empresa pueden compartirla (p. ej. dos
 * programas con 43232408). El identificador propio del producto sigue siendo
 * `no_identificacion`, que conserva su único por empresa. Se deja un índice
 * simple para las búsquedas por empresa y clave.
 *
 * Rollback: restaurar el único falla si para entonces una empresa ya tiene
 * dos productos con la misma clave; hay que resolverlos a mano antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->index(['company_id', 'clave_producto']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'clave_producto']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unique(['company_id', 'clave_producto']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'clave_producto']);
        });
    }
};
