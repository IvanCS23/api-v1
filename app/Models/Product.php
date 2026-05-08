<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
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
}
