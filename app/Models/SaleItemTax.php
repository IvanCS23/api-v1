<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\IsLineTax;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 6.34: un impuesto de SaleItem (ver IsLineTax).
 */
class SaleItemTax extends Model
{
    use BelongsToCompany, IsLineTax;

    protected $fillable = ['sale_item_id'];

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }
}
