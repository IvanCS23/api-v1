<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\IsLineTax;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 6.35: un impuesto de la factura prorrateado al monto pagado
 * (TrasladoDR / RetencionDR del complemento de pago). Ver IsLineTax.
 */
class PaymentAllocationTax extends Model
{
    use BelongsToCompany, IsLineTax;

    protected $fillable = ['payment_allocation_id'];

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(PaymentAllocation::class, 'payment_allocation_id');
    }
}
