<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.31 — Ambiente del PAC de cada factura (`test` o `live`).
 *
 * Lo escribe la primera reserva (PacEnvironmentAccess::bind) y nunca
 * cambia. Las facturas anteriores con operaciones del PAC se llenan con
 * `billing:backfill-pac-environment`; sin él quedan bloqueadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->enum('pac_environment', ['test', 'live'])->nullable()->after('pac_provider');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('pac_environment');
        });
    }
};
