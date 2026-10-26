<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fase 6.25: contrato explícito de la venta. Antes serializaba el modelo
 * completo (incluido deleted_at). `items`, `client` e `invoice` sólo
 * aparecen cuando el controller los carga; `invoice` es la factura vigente
 * resumida (o null).
 */
class SaleResource extends JsonResource
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
            'currency' => $this->currency,
            'payment_form' => $this->payment_form,
            'payment_method' => $this->payment_method,
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'tax_total' => $this->tax_total,
            'total' => $this->total,
            'notes' => $this->notes,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'client' => $this->whenLoaded('client', fn () => $this->client === null ? null : [
                'id' => $this->client->id,
                'name' => $this->client->name,
                'rfc' => $this->client->rfc,
            ]),
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice === null ? null : [
                'id' => $this->invoice->id,
                'folio' => $this->invoice->folio,
                'status' => $this->invoice->status?->value,
            ]),
            'items' => SaleItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
