<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Fase 6.35: pago recibido de una o varias facturas PPD del mismo cliente y
 * su complemento de pago (REP, CFDI tipo P, serie PAG). Sólo MXN.
 */
class Payment extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected $fillable = [
        'client_id',
        'payment_date',
        'payment_form',
        'currency',
        'exchange_rate',
        'amount',
        'operation_number',
        'notes',
    ];

    protected $casts = [
        'status' => PaymentStatus::class,
        'payment_date' => 'immutable_datetime',
        'exchange_rate' => 'decimal:6',
        'amount' => 'decimal:6',
        'pac_response' => 'encrypted:array',
        'pac_issue_attempts' => 'integer',
        'stamped_at' => 'immutable_datetime',
        'issued_at' => 'immutable_datetime',
        'cancellation_requested_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
    ];

    protected $attributes = [
        'status' => 'draft',
        'currency' => 'MXN',
        'exchange_rate' => 1,
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function allocations(): HasMany
    {
        // Acotada por el pago (que ya pasó el control de empresa).
        return $this->hasMany(PaymentAllocation::class)->withoutGlobalScope(CompanyScope::class)->orderBy('id');
    }

    public function isDraft(): bool
    {
        return $this->status === PaymentStatus::Draft;
    }

    /** Cuenta para el saldo de sus facturas: emitido, aunque su cancelación esté pendiente o rechazada. */
    public function countsForBalance(): bool
    {
        return $this->status === PaymentStatus::Issued;
    }
}
