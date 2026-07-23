<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo global (no BelongsToCompany a propósito): canal/instrumento
 * interno de cobro del ERP (efectivo, transferencia, tarjeta, cheque).
 *
 * NO representar aquí el "método de pago" (PUE|PPD) ni la "forma de
 * pago" (códigos c_FormaPago) del CFDI/SAT — son catálogos fiscales
 * distintos, todavía no creados, que se resolverán en la integración
 * con Facturapi (Fase 3). No calcula ni almacena lógica de cobros/pagos
 * — eso es Payments/Fase 2.
 */
class PaymentChannel extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'requires_bank',
        'active',
    ];

    protected $casts = [
        'requires_bank' => 'boolean',
        'active' => 'boolean',
    ];
}
