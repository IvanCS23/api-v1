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
 * IssueInvoiceService debe poder escribirlos, vía asignación
 * directa/forceFill(), nunca desde un payload de request masivo.
 *
 * Control de reserva/idempotencia (Fase 6.2.1, migración
 * `add_pac_issue_control_to_invoices_table`, aún no ejecutada):
 * `pac_idempotency_key`, `pac_issue_status`, `pac_issue_started_at`,
 * `pac_issue_attempts`, `pac_reconciliation_required` — mismo criterio,
 * tampoco son fillable. Describen el ciclo de vida del INTENTO de
 * emisión (reserva, reintentos, ambigüedad ante fallos de red),
 * separado de `pac_status` (que describe el resultado ya confirmado
 * por el PAC).
 *
 * `payment_form`/`payment_method` (Fase 6.2.2, migración
 * `add_payment_form_to_invoices_table`, aún no ejecutada): snapshot
 * fiscal, mismo criterio que `client_*` — SÍ son fillable (los fija
 * SaleToInvoiceConverter por asignación masiva al crear, copiándolos de
 * `Sale->company->default_payment_form`/`default_payment_method`, la
 * única fuente de este dato en el dominio hoy — Sale no lo posee
 * directamente). `UpdateInvoiceRequest` no los valida, así que —igual
 * que el resto del snapshot fiscal— tampoco son editables vía el
 * endpoint HTTP genérico una vez creada la Invoice. Nunca se vuelven a
 * leer desde Sale/Company después de la conversión: IssueInvoiceService
 * y FacturapiProvider usan exclusivamente este snapshot ya congelado.
 *
 * Tracking de BORRADOR remoto (Fase 6.2.4, migración
 * `add_pac_draft_tracking_to_invoices_table`, aún no ejecutada):
 * `pac_draft_external_id`, `pac_draft_status`, `pac_draft_ready_to_stamp`,
 * `pac_draft_idempotency_key`, `pac_draft_created_at`,
 * `pac_draft_last_sync_at`, `pac_draft_response` — mismo criterio, NO son
 * fillable (solo CreatePacDraftInvoiceService/SyncPacDraftInvoiceService
 * los escriben, vía forceFill()). Deliberadamente separadas por completo
 * de las columnas de emisión final (`pac_external_id`/`cfdi_uuid`/
 * `stamped_at`): un borrador es un recurso REAL en Facturapi pero nunca
 * timbrado — mezclarlas habría hecho imposible distinguir "existe un
 * borrador" de "existe un CFDI emitido".
 *
 * Artifacts CFDI (Fase 6.3, migración `add_cfdi_artifacts_to_invoices_table`,
 * aún no ejecutada): `cfdi_xml_path`/`cfdi_pdf_path` (rutas relativas
 * dentro del disk privado `local`, nunca el contenido en la DB),
 * `cfdi_xml_sha256`/`cfdi_pdf_sha256`/`cfdi_xml_size`/`cfdi_pdf_size`
 * (integridad), `cfdi_artifacts_downloaded_at`/`cfdi_artifacts_last_error`/
 * `cfdi_artifacts_status` — mismo criterio: NO son fillable, solo
 * `DownloadInvoiceArtifactsService` los escribe vía forceFill(). Las
 * rutas quedan ocultas en `$hidden` (ver InvoiceResource) — nunca deben
 * exponer la estructura de directorios del servidor vía API.
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
        'payment_form',
        'payment_method',
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
        'pac_draft_response',
        'cfdi_xml_path',
        'cfdi_pdf_path',
        'cfdi_artifacts_last_error',
        'cancellation_receipt_xml_path',
        'cancellation_receipt_pdf_path',
        'cancellation_receipt_last_error',
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
        'pac_issue_started_at' => 'immutable_datetime',
        'pac_issue_attempts' => 'integer',
        'pac_reconciliation_required' => 'boolean',
        'pac_draft_ready_to_stamp' => 'boolean',
        'pac_draft_created_at' => 'immutable_datetime',
        'pac_draft_last_sync_at' => 'immutable_datetime',
        'pac_draft_response' => 'encrypted:array',
        'cfdi_xml_size' => 'integer',
        'cfdi_pdf_size' => 'integer',
        'cfdi_artifacts_downloaded_at' => 'immutable_datetime',
        'cancellation_receipt_xml_size' => 'integer',
        'cancellation_receipt_pdf_size' => 'integer',
        'cancellation_receipt_downloaded_at' => 'immutable_datetime',
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

    public function pacEvents(): HasMany
    {
        return $this->hasMany(InvoicePacEvent::class);
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
