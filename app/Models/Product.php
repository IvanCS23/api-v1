<?php

namespace App\Models;

use App\Enums\ProductType;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'type',
        'no_identificacion',
        'descripcion',
        'precio_unitario',
        'cuenta_predial',
        'clave_producto',
        'clave_unidad',
        'objeto_imp',
        'tax_rate_id',
        'no_pedimento',
        'impuesto_local',
        'iva',
        'iva_retenido',
        'ieps',
        'isr',
    ];

    protected $casts = [
        'type' => ProductType::class,
        'precio_unitario' => 'decimal:2',
        'iva' => 'decimal:2',
        'iva_retenido' => 'decimal:2',
        'ieps' => 'decimal:2',
        'isr' => 'decimal:2',
    ];

    /**
     * Fase 6.26: IVA del producto (16 %, 0 % o exento) como tasa del catálogo;
     * `iva` queda como valor derivado (ver StoreProductRequest).
     */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }
}
