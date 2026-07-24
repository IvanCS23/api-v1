<?php

namespace App\Models;

use App\Enums\QuoteStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Services\Sales\HasCalculableItems;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quote extends Model implements HasCalculableItems
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    /**
     * `converted_sale_id`, `approved_at` y `converted_at` deliberadamente
     * NO son fillable (mismo criterio que `company_id`): solo
     * QuoteToSaleConverter/QuoteWorkflow los fijan, siempre vía
     * forceFill(), nunca a través de un payload de request (Fase 4 §5).
     */
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
        'status' => QuoteStatus::class,
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'total' => 'decimal:2',
        'approved_at' => 'datetime',
        'converted_at' => 'datetime',
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
        return $this->hasMany(QuoteItem::class);
    }

    public function convertedSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'converted_sale_id');
    }

    /**
     * Única fuente de verdad de si una cotización admite mutaciones
     * (edición, alta/edición/baja de líneas). Draft y Sent son editables;
     * Approved, Rejected, Expired y Converted son de solo lectura — la
     * única acción posible sobre una Approved es convert() (ver
     * QuoteToSaleConverter). Mismo patrón que Sale::isEditable().
     */
    public function isEditable(): bool
    {
        return in_array($this->status, [QuoteStatus::Draft, QuoteStatus::Sent], true);
    }
}
