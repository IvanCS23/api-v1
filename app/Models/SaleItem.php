<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SaleItem extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    /** Fase 6.34: sus impuestos viajan siempre con el modelo. */
    protected $with = ['taxes'];

    protected $fillable = [
        'sale_id',
        'product_id',
        'tax_rate_id',
        'description',
        'quantity',
        'unit_price',
        'discount',
        'subtotal',
        'tax_total',
        'taxes_transferred',
        'taxes_withheld',
        'total',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'discount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'taxes_transferred' => 'decimal:2',
        'taxes_withheld' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    // company() ya la provee el trait BelongsToCompany.

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    /** Fase 6.34: impuestos (traslados y retenciones), en orden. */
    public function taxes(): HasMany
    {
        return $this->hasMany(SaleItemTax::class)
            // Acotada por la línea (que ya pasó el control de empresa): sin el
            // scope de empresa, para no quedar vacía sin tenant activo.
            ->withoutGlobalScope(CompanyScope::class)
            ->orderBy('position')->orderBy('id');
    }
}
