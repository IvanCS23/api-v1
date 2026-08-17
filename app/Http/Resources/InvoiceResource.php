<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sin `toArray()` propio a propósito (ver SaleResource/QuoteResource):
 * JsonResource serializa el modelo tal cual, incluyendo `items` si el
 * controller hizo `$invoice->load('items')` antes de devolver el Resource.
 */
class InvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->whenHas('company_id'),
            'sale_id' => $this->whenHas('sale_id'),
            'client_id' => $this->whenHas('client_id'),
            'created_by' => $this->whenHas('created_by'),
            'folio' => $this->folio,
            'status' => $this->status,
            'client_name' => $this->client_name,
            'client_rfc' => $this->client_rfc,
            'client_regimen_fiscal' => $this->whenHas('client_regimen_fiscal'),
            'client_uso_cfdi' => $this->whenHas('client_uso_cfdi'),
            'client_codigo_postal' => $this->whenHas('client_codigo_postal'),
            'client_calle' => $this->whenHas('client_calle'),
            'client_no_exterior' => $this->whenHas('client_no_exterior'),
            'client_no_interior' => $this->whenHas('client_no_interior'),
            'client_colonia' => $this->whenHas('client_colonia'),
            'client_localidad' => $this->whenHas('client_localidad'),
            'client_municipio' => $this->whenHas('client_municipio'),
            'client_estado' => $this->whenHas('client_estado'),
            'client_pais' => $this->whenHas('client_pais'),
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'tax_total' => $this->tax_total,
            'total' => $this->total,
            'currency' => $this->currency,
            'notes' => $this->notes,
            'payment_form' => $this->payment_form,
            'payment_method' => $this->payment_method,
            'issued_at' => $this->issued_at,
            'cancelled_at' => $this->cancelled_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
