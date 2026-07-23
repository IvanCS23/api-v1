<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employe extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected $table = 'employes';

    protected $fillable = [
        'email',
        'nombre',
        'apellido_paterno',
        'apellido_materno',
        'curp',
        'rfc',
        'calle',
        'colonia',
        'no_exterior',
        'no_interior',
        'codigo_postal',
        'localidad',
        'municipio',
        'estado',
        'grupo',
        'sucursal',
        'salario_diario',
        'contrato',
        'regimen_contratacion',
        'tipo_jornada',
        'banco',
        'clave_bancaria',
        'periodidad_pago',
        'departamento',
        'puesto',
        'no_empleado',
        'seguro_social',
        'subcontratacion',
    ];

    protected $casts = [
        'salario_diario' => 'decimal:2',
    ];
}