<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sin `toArray()` propio a propósito (ver SaleItemResource).
 */
class InvoiceItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'product_id' => $this->product_id,
            'tax_rate_id' => $this->tax_rate_id,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'discount' => $this->discount,
            'subtotal' => $this->subtotal,
            'tax_total' => $this->tax_total,
            'total' => $this->total,
            'product_clave_producto' => $this->product_clave_producto,
            'product_clave_unidad' => $this->product_clave_unidad,
            'product_type' => $this->product_type,
            'product_no_identificacion' => $this->product_no_identificacion,
            'product_description' => $this->product_description,
            'product_objeto_imp' => $this->product_objeto_imp,
            'tax_code' => $this->tax_code,
            'tax_name' => $this->tax_name,
            'tax_rate_value' => $this->tax_rate_value,
            'tax_type' => $this->tax_type,
            'tax_factor_type' => $this->tax_factor_type,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
