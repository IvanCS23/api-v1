<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Dominio interno de facturación, completamente desacoplado de
 * cualquier PAC (Facturapi no está integrado — ver Fase 5). Una Invoice
 * nace siempre de una Sale Confirmed con billing-readiness `ready=true`
 * (ver SaleToInvoiceConverter), y copia como columnas propias todo el
 * snapshot fiscal del cliente al momento de facturar — nunca depende de
 * un join en vivo hacia `clients` (ver ERP_ARCHITECTURE_PLAN.md §12.1).
 *
 * `sale_id`/`client_id` se conservan como FKs de trazabilidad únicamente.
 *
 * Tracking PAC (Fase 6.1, migración `add_pac_tracking_to_invoices_table`,
 * aún no ejecutada): `pac_provider`, `pac_external_id`, `cfdi_uuid`,
 * `pac_status`, `cancellation_status`, `stamped_at`, `last_pac_sync_at`,
 * `pac_response`, `pac_last_error` deliberadamente NO son fillable — solo
 * un futuro servicio interno de timbrado (no implementado todavía) debe
 * poder escribirlos, vía asignación directa/forceFill(), nunca desde un
 * payload de request masivo.
 */
class Invoice extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    /**
     * `sale_id`, `created_by`, `folio`, `issued_at`, `cancelled_at` NO
     * son fillable a propósito (mismo criterio que Sale/Quote): solo
     * SaleToInvoiceConverter/InvoiceWorkflow los fijan, siempre vía
     * asignación directa o forceFill(), nunca desde un payload de
     * request.
     */
    protected $fillable = [
        'client_id',
        'status',
        'subtotal',
        'discount_total',
        'tax_total',
        'total',
        'currency',
        'notes',
        'client_name',
        'client_rfc',
        'client_regimen_fiscal',
        'client_uso_cfdi',
        'client_codigo_postal',
        'client_calle',
        'client_no_exterior',
        'client_no_interior',
        'client_colonia',
        'client_localidad',
        'client_municipio',
        'client_estado',
        'client_pais',
    ];

    /**
     * Campos de tracking PAC (Fase 6.1) ocultos por defecto en
     * serialización: `pac_response` es la respuesta cruda del proveedor
     * (puede incluir datos internos no destinados al cliente de la API)
     * y `pac_last_error` es un detalle de diagnóstico interno — ninguno
     * debe llegar nunca a un Resource/response JSON.
     */
    protected $hidden = [
        'pac_response',
        'pac_last_error',
    ];

    protected $casts = [
        'status' => InvoiceStatus::class,
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'total' => 'decimal:2',
        'issued_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'stamped_at' => 'immutable_datetime',
        'last_pac_sync_at' => 'immutable_datetime',
        'pac_response' => 'encrypted:array',
    ];

    // company() ya la provee el trait BelongsToCompany.

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

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
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * Draft y Ready admiten cambios de datos propios (ej. notes) y
     * eliminación. Issued y Cancelled son terminales — Issued en
     * particular debe ser completamente inmutable (requisito explícito
     * de Fase 5), y Cancelled no tiene sentido reabrir.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, [InvoiceStatus::Draft, InvoiceStatus::Ready], true);
    }
}
