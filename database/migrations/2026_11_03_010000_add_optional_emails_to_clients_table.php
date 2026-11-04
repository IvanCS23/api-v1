<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.30 — Dos correos opcionales del cliente, además del principal.
 *
 * El formulario de Clientes ya mostraba "E-Mail opcional No.1/No.2", pero no
 * los enviaba ni existían en la BD. Con el principal forman los destinatarios
 * por defecto al enviar una factura por correo. No son únicos: varios
 * clientes pueden compartir, p. ej., el correo de su contador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('email_optional_1')->nullable()->after('email');
            $table->string('email_optional_2')->nullable()->after('email_optional_1');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn(['email_optional_1', 'email_optional_2']);
        });
    }
};
