<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.31 — Ambiente de la empresa y llave Live.
 *
 * - pac_environment: `test` o `live`, nunca vacío (enum con default
 *   `test`). Sólo lo cambia `billing:pac-environment` desde la consola.
 * - live_secret_key: llave Live cifrada (cast `encrypted`, oculta). Sólo la
 *   guarda `billing:facturapi-live-key` con un prompt oculto.
 * - live_key_hint / live_key_set_at: lo único que se muestra de ella.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturapi_organizations', function (Blueprint $table): void {
            $table->enum('pac_environment', ['test', 'live'])->default('test')->after('organization_id');
            $table->text('live_secret_key')->nullable()->after('test_key_set_at');
            $table->string('live_key_hint', 32)->nullable()->after('live_secret_key');
            $table->timestamp('live_key_set_at')->nullable()->after('live_key_hint');
        });
    }

    public function down(): void
    {
        Schema::table('facturapi_organizations', function (Blueprint $table): void {
            $table->dropColumn(['pac_environment', 'live_secret_key', 'live_key_hint', 'live_key_set_at']);
        });
    }
};
