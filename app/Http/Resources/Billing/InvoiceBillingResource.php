<?php

namespace App\Http\Resources\Billing;

use App\Enums\InvoicePacEventType;
use App\Services\Billing\InvoiceBusinessCapabilitiesService;
use App\Services\Billing\SendInvoiceEmailService;
use App\Support\Billing\InvoiceEmailRecipients;
use App\Support\Billing\InvoiceFolio;
use App\Support\Billing\PacEnvironment;
use App\Support\Billing\PacEnvironmentAccess;
use App\Support\Billing\PacErrorCode;
use App\Support\Billing\PacOrganizationAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato público explícito del snapshot fiscal local de una Invoice.
 * Nunca serializa el modelo completo ni consulta Storage/PAC.
 */
class InvoiceBillingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $artifactsStored = $this->cfdi_artifacts_status === 'stored';
        $receiptStored = $this->cancellation_receipt_status === 'stored';

        $actions = app(InvoiceBusinessCapabilitiesService::class)->for($this->resource, $request->user());

        return [
            'id' => $this->id,
            'folio' => $this->folio,
            'status' => $this->status?->value,
            'actions' => $actions,
            'pac' => [
                // Fase 6.22: modo configurado (test|live), nunca la llave ni
                // un fragmento de ella. Permite explicar los acuses de TEST.
                'mode' => PacEnvironment::display(PacEnvironment::forInvoice($this->resource)),
                'provider' => $this->pac_provider,
                'status' => $this->pac_status,
                'cancellation_status' => $this->cancellation_status,
                'issue_status' => $this->pac_issue_status,
                'reconciliation_required' => (bool) $this->pac_reconciliation_required,
                'last_sync_at' => $this->last_pac_sync_at?->toIso8601String(),
                // Fase 6.27: current | other | archived | unknown (null si la
                // factura nunca llegó al PAC). Nunca el ID de la organización.
                'organization_status' => PacOrganizationAccess::status($this->resource, PacOrganizationAccess::companyOrganizationId((int) $this->company_id)),
                'organization_archived_at' => $this->pac_organization_archived_at?->toIso8601String(),
                // Fase 6.31: bound | mismatch | unbound (null si la factura
                // todavía no tiene ambiente ni operaciones del PAC).
                'environment_status' => PacEnvironmentAccess::statusFor($this->resource),
            ],
            'cfdi' => [
                'uuid' => $this->cfdi_uuid,
                // Fase 6.28: el folio que muestran el XML y el PDF.
                'series' => $this->cfdi_series,
                'folio_number' => $this->cfdi_folio_number,
                'folio' => InvoiceFolio::label($this->cfdi_series, $this->cfdi_folio_number),
                'folio_matches_erp' => InvoiceFolio::matchesErp($this->folio, $this->cfdi_series, $this->cfdi_folio_number),
                'stamped_at' => $this->stamped_at?->toIso8601String(),
                'artifacts' => [
                    'status' => $this->cfdi_artifacts_status,
                    'xml_available' => $artifactsStored && filled($this->cfdi_xml_path),
                    'pdf_available' => $artifactsStored && filled($this->cfdi_pdf_path),
                    'downloaded_at' => $this->cfdi_artifacts_downloaded_at?->toIso8601String(),
                ],
            ],
            'cancellation_receipt' => [
                'status' => $this->cancellation_receipt_status,
                'available' => $receiptStored
                    && filled($this->cancellation_receipt_xml_path)
                    && filled($this->cancellation_receipt_pdf_path),
                'downloaded_at' => $this->cancellation_receipt_downloaded_at?->toIso8601String(),
                'error_code' => $this->cancellationReceiptErrorCode(),
            ],
            // Fase 6.30: sólo para quien puede enviar; los destinatarios salen de
            // nuestra base (cliente), nunca de Facturapi.
            'email' => $actions['can_send_email'] ? [
                'default_recipients' => app(SendInvoiceEmailService::class)->defaultRecipients($this->resource),
                'max_recipients' => InvoiceEmailRecipients::MAX,
                'last_sent_at' => $this->lastEmailSentAt(),
            ] : null,
            'timeline' => InvoicePacEventResource::collection($this->whenLoaded('pacEvents')),
        ];
    }

    private function lastEmailSentAt(): ?string
    {
        $sentAt = $this->resource->pacEvents()
            ->where('event_type', InvoicePacEventType::EmailSent->value)
            ->max('occurred_at');

        return $sentAt === null ? null : CarbonImmutable::parse($sentAt, 'UTC')->toIso8601String();
    }

    private function cancellationReceiptErrorCode(): ?string
    {
        return PacErrorCode::from($this->cancellation_receipt_last_error);
    }
}
