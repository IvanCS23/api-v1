<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'email_optional_1',
        'email_optional_2',
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
        'pais',
    ];
}
