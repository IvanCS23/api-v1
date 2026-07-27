<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Línea de una Invoice: snapshot fiscal congelado del producto y de la
 * tasa de impuesto al momento de facturar (columnas `product_*`/`tax_*`).
 * `product_id`/`tax_rate_id` se conservan nullable con `nullOnDelete`
 * solo para trazabilidad histórica, nunca como fuente de verdad — ver
 * ERP_ARCHITECTURE_PLAN.md §12.1.
 *
 * Nunca se crea/edita/elimina manualmente: la genera exclusivamente
 * SaleToInvoiceConverter al convertir. No implementa HasCalculableItems
 * ni usa LineItemCalculator — sus importes son una copia congelada de
 * los del SaleItem de origen, no un cálculo recurrente.
 */
class InvoiceItem extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected $fillable = [
        'invoice_id',
        'product_id',
        'tax_rate_id',
        'description',
        'quantity',
        'unit_price',
        'discount',
        'subtotal',
        'tax_total',
        'total',
        'product_clave_producto',
        'product_clave_unidad',
        'product_type',
        'tax_code',
        'tax_name',
        'tax_rate_value',
        'tax_type',
        'tax_factor_type',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'discount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'total' => 'decimal:2',
        'tax_rate_value' => 'decimal:6',
    ];

    // company() ya la provee el trait BelongsToCompany.

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }
}
