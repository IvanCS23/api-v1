<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.27 — Organización Facturapi por empresa.
 *
 * Una fila por empresa (1:1): la organización de Facturapi donde timbra y
 * su llave secreta de ambiente Test. La llave vive en esta tabla y no en
 * `companies` porque la empresa se carga en muchos lugares (UserResource,
 * policies); aquí queda aislada en un modelo propio, cifrada en reposo
 * (cast `encrypted`, APP_KEY) y oculta en serialización.
 *
 * - organization_id: único — dos empresas nunca comparten organización.
 * - test_key_hint: `sk_test_…a1b2`, lo único que se muestra de la llave.
 * - legal_* / certificate_* / is_production_ready / pending_steps: copia
 *   de lo que reporta Facturapi (GET /organizations/me) al vincular; no
 *   son secretos. `snapshot_at` es la fecha de esa copia.
 *
 * La llave Live se agrega en la fase de producción, no aquí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facturapi_organizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('organization_id', 64)->unique();
            $table->text('test_secret_key')->nullable();
            $table->string('test_key_hint', 32)->nullable();
            $table->timestamp('test_key_set_at')->nullable();
            $table->string('legal_name')->nullable();
            $table->string('legal_tax_id', 13)->nullable();
            $table->string('legal_tax_system', 3)->nullable();
            $table->string('legal_zip', 10)->nullable();
            $table->boolean('has_certificate')->default(false);
            $table->string('certificate_serial_number', 40)->nullable();
            $table->timestamp('certificate_expires_at')->nullable();
            $table->timestamp('certificate_updated_at')->nullable();
            $table->boolean('is_production_ready')->default(false);
            $table->json('pending_steps')->nullable();
            $table->timestamp('snapshot_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facturapi_organizations');
    }
};
