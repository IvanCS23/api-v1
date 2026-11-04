<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.30 — Envío automático del CFDI por correo después del timbrado,
 * opcional por empresa. Apagado por defecto: en modo test Facturapi puede
 * entregar el correo de verdad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->boolean('auto_email_invoices')->default(false)->after('default_payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('auto_email_invoices');
        });
    }
};
