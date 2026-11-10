<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\IsLineTax;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 6.34: un impuesto de InvoiceItem (ver IsLineTax).
 */
class InvoiceItemTax extends Model
{
    use BelongsToCompany, IsLineTax;

    protected $fillable = ['invoice_item_id'];

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }
}
