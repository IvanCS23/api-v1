<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.27 — `companies.api_key` era texto plano, asignable en masa y sin
 * uso. La llave del PAC vive ahora cifrada en `facturapi_organizations`.
 *
 * Aborta si alguna empresa tiene un valor: nunca se descarta una llave en
 * silencio; hay que moverla a mano a su organización antes de migrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        $withKey = DB::table('companies')->whereNotNull('api_key')->where('api_key', '<>', '')->count();

        if ($withKey > 0) {
            throw new RuntimeException("companies.api_key tiene valor en {$withKey} empresa(s); muévelo a facturapi_organizations antes de eliminar la columna.");
        }

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('api_key');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('api_key')->nullable()->after('sandbox');
        });
    }
};
