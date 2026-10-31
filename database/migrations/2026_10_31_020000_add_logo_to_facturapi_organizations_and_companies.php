<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.29 — Logotipo y nombre comercial del PDF.
 *
 * - facturapi_organizations.legal_trade_name / has_logo: copia de
 *   `legal.name` (nombre comercial) y `customization.has_logo` de la
 *   organización, para que Datos fiscales muestre lo que imprime el PDF.
 * - companies.logo (ya existía, sin uso) guarda la ruta de la copia
 *   privada del logotipo subido; companies.logo_updated_at, cuándo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturapi_organizations', function (Blueprint $table): void {
            $table->string('legal_trade_name')->nullable()->after('legal_name');
            $table->boolean('has_logo')->nullable()->after('certificate_updated_at');
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->timestamp('logo_updated_at')->nullable()->after('logo');
        });
    }

    public function down(): void
    {
        Schema::table('facturapi_organizations', function (Blueprint $table): void {
            $table->dropColumn(['legal_trade_name', 'has_logo']);
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('logo_updated_at');
        });
    }
};
