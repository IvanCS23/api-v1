<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    protected $fillable = [
    'name',
    'email',
    'rfc',
    'codigo_postal',
    'regimen_fiscal',
    'uso_cfdi',
    'calle',
    'no_exterior',
    'no_interior',
    'colonia',
    'localidad',
    'municipio',
    'estado',
    'pais'
];
}
