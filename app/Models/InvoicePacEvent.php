<?php

namespace App\Models;

use App\Enums\InvoicePacEventType;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Evento fiscal PAC inmutable. Solo InvoicePacAuditService debe crearlo.
 */
class InvoicePacEvent extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['context'];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Los eventos PAC son inmutables y no pueden actualizarse.');
        });

        static::deleting(function (): never {
            throw new LogicException('Los eventos PAC son append-only y no pueden eliminarse.');
        });
    }

    protected function casts(): array
    {
        return [
            'event_type' => InvoicePacEventType::class,
            'context' => 'encrypted:array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
