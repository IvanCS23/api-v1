<?php

namespace App\Services\Sales;

use App\Enums\QuoteStatus;
use App\Enums\SaleStatus;
use App\Models\Quote;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Convierte una Quote (debe estar Approved) en una Sale nueva.
 *
 * La Quote original se preserva SIN modificaciones estructurales: sus
 * líneas (`quote_items`) y sus totales nunca se tocan. Lo único que
 * cambia en la propia Quote es `status` (→ Converted) y
 * `converted_sale_id` (referencia a la venta creada) — exactamente lo
 * pedido en Fase 3 §7.
 *
 * La Sale resultante se crea ya `Confirmed` (no `Draft`): el acuerdo
 * comercial ya se aprobó en la cotización, así que no tiene sentido que
 * la venta derivada vuelva a pasar por un estado de borrador.
 */
class QuoteToSaleConverter
{
    public function __construct(private readonly SaleNumberGenerator $numberGenerator) {}

    public function convert(Quote $quote): Sale
    {
        return DB::transaction(function () use ($quote): Sale {
            $sale = Sale::create([
                'client_id' => $quote->client_id,
                'created_by' => $quote->created_by,
                'folio' => $this->numberGenerator->next($quote->company_id),
                'status' => SaleStatus::Confirmed,
                'subtotal' => $quote->subtotal,
                'discount_total' => $quote->discount_total,
                'tax_total' => $quote->tax_total,
                'total' => $quote->total,
                'currency' => $quote->currency,
                'notes' => $quote->notes,
            ]);

            foreach ($quote->items as $quoteItem) {
                $sale->items()->create([
                    'product_id' => $quoteItem->product_id,
                    'tax_rate_id' => $quoteItem->tax_rate_id,
                    'description' => $quoteItem->description,
                    'quantity' => $quoteItem->quantity,
                    'unit_price' => $quoteItem->unit_price,
                    'discount' => $quoteItem->discount,
                    'subtotal' => $quoteItem->subtotal,
                    'tax_total' => $quoteItem->tax_total,
                    'total' => $quoteItem->total,
                ]);
            }

            $quote->update([
                'status' => QuoteStatus::Converted,
                'converted_sale_id' => $sale->id,
            ]);

            return $sale;
        });
    }
}
