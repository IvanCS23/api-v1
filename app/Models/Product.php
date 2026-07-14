<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
    'name',
    'no_identificacion',
    'descripcion',
    'precio_unitario',
    'cuenta_predial',
    'clave_producto',
    'clave_unidad',
    'objeto_imp',
    'no_pedimento',
    'impuesto_local',
    'iva',
    'iva_retenido',
    'ieps',
    'isr'
    ];

    protected $casts = [
        'precio_unitario' => 'decimal:2',
        'iva' => 'decimal:2',
        'iva_retenido' => 'decimal:2',
        'ieps' => 'decimal:2',
        'isr' => 'decimal:2',
    ];
}
