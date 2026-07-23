<?php

namespace App\Models;

use App\Enums\TaxFactorType;
use App\Enums\TaxType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo global (no BelongsToCompany): tasas de impuestos nacionales.
 * No calcula impuestos de ventas/facturas todavía — eso es Fase 2/3.
 */
class TaxRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'rate',
        'tax_type',
        'factor_type',
        'active',
    ];

    protected $casts = [
        'rate' => 'decimal:6',
        'tax_type' => TaxType::class,
        'factor_type' => TaxFactorType::class,
        'active' => 'boolean',
    ];
}
