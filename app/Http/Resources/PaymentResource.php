<?php

namespace App\Http\Resources;

use App\Services\Payments\PaymentReceiptDeadline;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fase 6.35: contrato del pago recibido. Nunca expone pac_response ni
 * pac_last_error. `allocations` y `client` sólo aparecen si el controlador
 * los carga.
 */
class PaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'client_id' => $this->client_id,
            'created_by' => $this->created_by,
            'folio' => $this->folio,
            'status' => $this->status?->value,
            'payment_date' => $this->payment_date?->toIso8601String(),
            'payment_form' => $this->payment_form,
            'currency' => $this->currency,
            'exchange_rate' => $this->exchange_rate,
            'amount' => $this->amount,
            'operation_number' => $this->operation_number,
            // Fase 6.35 (D9): aviso del plazo del REP (quinto día natural del
            // mes siguiente al pago); el estado sólo aplica a borradores.
            'rep_deadline' => $this->payment_date !== null ? PaymentReceiptDeadline::for($this->payment_date)->toDateString() : null,
            'rep_deadline_status' => PaymentReceiptDeadline::status($this->resource),
            'notes' => $this->notes,
            'cfdi_uuid' => $this->cfdi_uuid,
            'pac_environment' => $this->pac_environment,
            'pac_issue_status' => $this->pac_issue_status,
            'stamped_at' => $this->stamped_at?->toIso8601String(),
            'cancellation_status' => $this->cancellation_status,
            'cancellation_motive' => $this->cancellation_motive,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client->id,
                'name' => $this->client->name,
                'rfc' => $this->client->rfc,
            ]),
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($allocation) => [
                'id' => $allocation->id,
                'invoice_id' => $allocation->invoice_id,
                'invoice_folio' => $allocation->relationLoaded('invoice') ? $allocation->invoice?->folio : null,
                'invoice_uuid' => $allocation->invoice_uuid ?? ($allocation->relationLoaded('invoice') ? $allocation->invoice?->cfdi_uuid : null),
                'installment' => $allocation->installment,
                'previous_balance' => $allocation->previous_balance,
                'amount' => $allocation->amount,
                'remaining_balance' => $allocation->remaining_balance,
                'taxes' => LineTaxResource::collection($allocation->taxes),
            ])->values()),
        ];
    }
}
