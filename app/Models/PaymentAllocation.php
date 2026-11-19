<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fase 6.35: parte de un pago aplicada a una factura PPD (documento
 * relacionado del REP), con su parcialidad y saldos (6 decimales).
 */
class PaymentAllocation extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'payment_id',
        'invoice_id',
        'installment',
        'previous_balance',
        'amount',
        'remaining_balance',
        'invoice_uuid',
        'invoice_series',
        'invoice_folio_number',
        'invoice_currency',
        'invoice_total',
    ];

    protected $casts = [
        'installment' => 'integer',
        'previous_balance' => 'decimal:6',
        'amount' => 'decimal:6',
        'remaining_balance' => 'decimal:6',
        'invoice_folio_number' => 'integer',
        'invoice_total' => 'decimal:6',
    ];

    /** Fase 6.35: sus impuestos viajan siempre con ella. */
    protected $with = ['taxes'];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class)->withoutGlobalScope(CompanyScope::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withoutGlobalScope(CompanyScope::class);
    }

    public function taxes(): HasMany
    {
        return $this->hasMany(PaymentAllocationTax::class)
            ->withoutGlobalScope(CompanyScope::class)
            ->orderBy('position')->orderBy('id');
    }
}
