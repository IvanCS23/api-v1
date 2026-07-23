<?php

namespace App\Models;

use App\Enums\SaleStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Services\Sales\HasCalculableItems;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sale extends Model implements HasCalculableItems
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected $fillable = [
        'client_id',
        'created_by',
        'folio',
        'status',
        'subtotal',
        'discount_total',
        'tax_total',
        'total',
        'currency',
        'notes',
    ];

    protected $casts = [
        'status' => SaleStatus::class,
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    // company() ya la provee el trait BelongsToCompany.

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }
}
