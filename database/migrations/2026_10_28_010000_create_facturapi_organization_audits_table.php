<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.27b — Auditoría de la organización de Facturapi de cada empresa:
 * creación, reemplazo de llave, datos fiscales y CSD.
 *
 * - user_id: quien hizo el cambio desde la API; null cuando lo ejecutó un
 *   comando del operador (source = console).
 * - metadata: sólo datos no secretos (campos cambiados, serie y vigencia
 *   del CSD, pista de la llave). Nunca llaves, contraseñas ni archivos.
 *
 * Append-only, igual que invoice_pac_events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facturapi_organization_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('organization_id', 64)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 16);
            $table->string('action', 64);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facturapi_organization_audits');
    }
};
