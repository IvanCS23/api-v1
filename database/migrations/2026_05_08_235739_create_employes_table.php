<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employes', function (Blueprint $table) {
            $table->id();

    $table->string('email')->unique();
    $table->string('nombre');
    $table->string('apellido_paterno');
    $table->string('apellido_materno');
    $table->string('curp', 18)->unique();
    $table->string('rfc', 13)->unique();
    $table->string('calle');
    $table->string('colonia');
    $table->string('no_exterior');
    $table->string('no_interior')->nullable();
    $table->string('codigo_postal', 5);
    $table->string('localidad');
    $table->string('municipio');
    $table->string('estado');
    $table->string('grupo');
    $table->string('sucursal');
    $table->decimal('salario_diario', 10, 2);
    $table->string('contrato');
    $table->string('regimen_contratacion');
    $table->string('tipo_jornada');
    $table->string('banco')->nullable();
    $table->string('clave_bancaria', 18)->nullable()->unique();
    $table->string('periodidad_pago');
    $table->string('departamento');
    $table->string('puesto');
    $table->string('no_empleado')->unique();
    $table->string('seguro_social', 11)->unique();
    $table->string('subcontratacion')->nullable();
    $table->timestamps();
    $table->index('no_empleado');
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employes');
    }
};
