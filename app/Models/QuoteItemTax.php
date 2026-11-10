<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\IsLineTax;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 6.34: un impuesto de QuoteItem (ver IsLineTax).
 */
class QuoteItemTax extends Model
{
    use BelongsToCompany, IsLineTax;

    protected $fillable = ['quote_item_id'];

    public function quoteItem(): BelongsTo
    {
        return $this->belongsTo(QuoteItem::class);
    }
}
