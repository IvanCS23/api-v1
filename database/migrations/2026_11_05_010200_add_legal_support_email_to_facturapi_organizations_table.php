<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.31 — Copia local de `legal.support_email` de la organización.
 *
 * Es el "Responder" de los correos que Facturapi envía al cliente (6.30).
 * En Live la emisión exige que coincida con el correo de contacto de la
 * empresa. Se llena con cada respuesta de la organización (Datos fiscales,
 * CSD, logotipo y el cambio de ambiente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturapi_organizations', function (Blueprint $table): void {
            $table->string('legal_support_email')->nullable()->after('legal_zip');
        });
    }

    public function down(): void
    {
        Schema::table('facturapi_organizations', function (Blueprint $table): void {
            $table->dropColumn('legal_support_email');
        });
    }
};
