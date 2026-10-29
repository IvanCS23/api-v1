<?php

namespace App\Models;

use App\Enums\FacturapiOrganizationAuditAction;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Fase 6.27b — Registro append-only de cambios a la organización de
 * Facturapi de una empresa. Se escribe con FacturapiOrganizationAuditor.
 */
class FacturapiOrganizationAudit extends Model
{
    use BelongsToCompany;

    public const SOURCE_API = 'api';

    public const SOURCE_CONSOLE = 'console';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = [
        'action' => FacturapiOrganizationAuditAction::class,
        'metadata' => 'array',
        'created_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('La auditoría de la organización es inmutable.');
        });

        static::deleting(function (): never {
            throw new LogicException('La auditoría de la organización es append-only.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
