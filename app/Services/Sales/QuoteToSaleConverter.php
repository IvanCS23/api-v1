<?php

namespace App\Services\Sales;

use App\Enums\QuoteStatus;
use App\Enums\SaleStatus;
use App\Exceptions\QuoteAlreadyConvertedException;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Scopes\CompanyScope;
use Illuminate\Support\Facades\DB;

/**
 * Convierte una Quote (debe estar Approved) en una Sale nueva.
 *
 * La Quote original se preserva SIN modificaciones estructurales: sus
 * líneas (`quote_items`) y sus totales nunca se tocan. Lo único que
 * cambia en la propia Quote es `status` (→ Converted), `converted_sale_id`
 * (referencia a la venta creada) y `converted_at` (Fase 4) — exactamente
 * lo pedido en Fase 3 §7.
 *
 * La Sale resultante se crea ya `Confirmed` (no `Draft`): el acuerdo
 * comercial ya se aprobó en la cotización, así que no tiene sentido que
 * la venta derivada vuelva a pasar por un estado de borrador.
 *
 * Concurrencia: todo ocurre dentro de una transacción, y la Quote se
 * vuelve a leer con lockForUpdate() antes de decidir nada. La validación
 * de "está Approved y no convertida" del controller es solo un fast-fail
 * optimista; la única verificación que realmente importa es la que se
 * hace aquí, ya con el lock adquirido — así dos requests concurrentes
 * sobre la misma Quote nunca pueden generar dos Sales. Si al adquirir el
 * lock la Quote ya no cumple las condiciones (porque otra request ganó
 * la carrera, o porque es un segundo intento sobre una Quote ya
 * convertida), se lanza QuoteAlreadyConvertedException sin crear nada.
 * El lock también reafirma `where('company_id', $quote->company_id)`
 * explícitamente (con el company_id de la instancia ya tenant-scoped
 * recibida, nunca del request): `withoutGlobalScope()` por sí solo no
 * debe ser la única barrera contra bloquear/convertir una Quote de otra
 * empresa (endurecimiento defensivo, auditoría Fase 4 — cierre).
 *
 * Integridad de company_id: la Sale y cada SaleItem toman su company_id
 * directamente del company_id de la Quote bloqueada (asignación directa
 * de atributo antes de save(), el mecanismo de confianza que reconoce
 * BelongsToCompany) — nunca de CurrentTenant ni de ningún dato de la
 * petición. Esto hace la conversión correcta incluso si se invocara
 * fuera de un request HTTP (consola/job) donde CurrentTenant pudiera no
 * coincidir. client_id/product_id/tax_rate_id nunca se leen del request:
 * se copian tal cual de la Quote/QuoteItem, que ya fueron validados
 * contra la misma empresa al momento de su propia creación.
 */
class QuoteToSaleConverter
{
    public function __construct(private readonly SaleNumberGenerator $numberGenerator) {}

    public function convert(Quote $quote): Sale
    {
        return DB::transaction(function () use ($quote): Sale {
            $lockedQuote = Quote::withoutGlobalScope(CompanyScope::class)
                ->whereKey($quote->getKey())
                ->where('company_id', $quote->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedQuote->status !== QuoteStatus::Approved || $lockedQuote->converted_sale_id !== null) {
                throw new QuoteAlreadyConvertedException($lockedQuote);
            }

            $sale = new Sale([
                'client_id' => $lockedQuote->client_id,
                'created_by' => $lockedQuote->created_by,
                'folio' => $this->numberGenerator->next($lockedQuote->company_id),
                'status' => SaleStatus::Confirmed,
                'subtotal' => $lockedQuote->subtotal,
                'discount_total' => $lockedQuote->discount_total,
                'tax_total' => $lockedQuote->tax_total,
                'total' => $lockedQuote->total,
                'currency' => $lockedQuote->currency,
                'notes' => $lockedQuote->notes,
            ]);
            $sale->company_id = $lockedQuote->company_id;
            $sale->save();

            $quoteItems = QuoteItem::withoutGlobalScope(CompanyScope::class)
                ->where('quote_id', $lockedQuote->id)
                ->get();

            foreach ($quoteItems as $quoteItem) {
                $item = new SaleItem([
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
                $item->company_id = $sale->company_id;
                $item->sale_id = $sale->id;
                $item->save();
            }

            $lockedQuote->forceFill([
                'status' => QuoteStatus::Converted,
                'converted_sale_id' => $sale->id,
                'converted_at' => now(),
            ])->save();

            return $sale;
        });
    }
}
