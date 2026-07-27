<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sistema legado de tickets (MVP pre-multiempresa), renombrado desde
 * `Invoice`/`invoices` en Fase 5 para liberar ese nombre para el nuevo
 * dominio fiscal real (ver `App\Models\Invoice`). Sin `company_id`, sin
 * relación con Sale — se conserva tal cual, de solo lectura histórica
 * salvo por el CRUD legado que ya existía.
 */
class LegacyInvoice extends Model
{
    use SoftDeletes;

    protected $table = 'legacy_invoices';

    protected $fillable = [
        'invoice_number',
        'status',
        'document_type',
        'emitter_name',
        'emitter_zip',
        'client_id',
        'client_snapshot',
        'payment',
        'options',
        'items',
        'subtotal',
        'taxes',
        'discount',
        'total',
        'currency',
        'exchange_rate',
        'issued_at',
    ];

    protected $casts = [
        'client_snapshot' => 'array',
        'payment' => 'array',
        'options' => 'array',
        'items' => 'array',
        'subtotal' => 'decimal:2',
        'taxes' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'issued_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
