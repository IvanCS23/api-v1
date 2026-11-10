<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\IsLineTax;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 6.34: un impuesto de Product (ver IsLineTax).
 */
class ProductTax extends Model
{
    use BelongsToCompany, IsLineTax;

    protected $fillable = ['product_id'];

    protected function hasAmounts(): bool
    {
        return false;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
