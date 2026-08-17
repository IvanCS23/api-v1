<?php

namespace App\Http\Resources\Billing;

use App\Services\Billing\InvoiceBusinessCapabilitiesService;
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

        return [
            'id' => $this->id,
            'folio' => $this->folio,
            'status' => $this->status?->value,
            'actions' => app(InvoiceBusinessCapabilitiesService::class)->for($this->resource, $request->user()),
            'pac' => [
                'provider' => $this->pac_provider,
                'status' => $this->pac_status,
                'cancellation_status' => $this->cancellation_status,
                'issue_status' => $this->pac_issue_status,
                'reconciliation_required' => (bool) $this->pac_reconciliation_required,
                'last_sync_at' => $this->last_pac_sync_at?->toIso8601String(),
            ],
            'cfdi' => [
                'uuid' => $this->cfdi_uuid,
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
            'timeline' => InvoicePacEventResource::collection($this->whenLoaded('pacEvents')),
        ];
    }

    private function cancellationReceiptErrorCode(): ?string
    {
        $error = $this->cancellation_receipt_last_error;

        if (! is_string($error)
            || preg_match('/^\[([A-Z0-9_.-]{1,100})\](?:\s|$)/', $error, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
