<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.2.3 — corrige `companies.default_payment_method`.
 *
 * Hallazgo (Fase 6.2.2, confirmado aquí por inspección directa de
 * `SHOW CREATE TABLE companies` contra la conexión MySQL/MariaDB real
 * del proyecto): la columna quedó definida como
 * `varchar(2) DEFAULT NULL` desde `2026_06_29_000000_create_companies_table`,
 * pero el catálogo SAT c_MetodoPago que realmente usa Facturapi son los
 * literales "PUE"/"PPD" — 3 caracteres, no 2. Con la definición
 * original, ningún valor real cabía sin truncarse.
 *
 * Atributos originales preservados sin cambio (confirmados por SHOW
 * CREATE TABLE antes de escribir esta migración): nullable, sin default,
 * sin índice propio, sin relación con ninguna unique key de la tabla
 * (`companies_uuid_unique`, `companies_rfc_unique`,
 * `companies_email_unique` no la involucran). Únicamente cambia la
 * longitud de 2 a 3.
 *
 * `->change()` no requiere doctrine/dbal en Laravel 12 (el grammar de
 * MySQL/SQLite lo compila de forma nativa) — no se agrega ninguna
 * dependencia nueva.
 *
 * NO modifica `2026_06_29_000000_create_companies_table.php` (ya
 * ejecutada). No se ejecuta realmente en este sprint (solo
 * `migrate --pretend`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('default_payment_method', 3)->nullable()->change();
        });
    }

    /**
     * Revierte a varchar(2) únicamente si es seguro: si alguna fila ya
     * tiene un valor de 3 caracteres (ej. "PUE"/"PPD" ya capturados
     * tras aplicar `up()`), truncarlo de vuelta a 2 perdería datos
     * reales — en ese caso se rehúsa el rollback en vez de corromper
     * silenciosamente la columna.
     */
    public function down(): void
    {
        $hasLongerValues = DB::table('companies')
            ->whereNotNull('default_payment_method')
            ->whereRaw('LENGTH(default_payment_method) > 2')
            ->exists();

        if ($hasLongerValues) {
            throw new RuntimeException(
                'No se puede revertir default_payment_method a varchar(2): existen filas con valores de 3 '.
                'caracteres (ej. "PUE"/"PPD") que se truncarían y perderían datos reales. Corrige o limpia '.
                'esos valores manualmente antes de intentar este rollback.',
            );
        }

        Schema::table('companies', function (Blueprint $table): void {
            $table->string('default_payment_method', 2)->nullable()->change();
        });
    }
};
