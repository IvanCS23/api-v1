<?php

namespace App\Http\Controllers\Api;

use App\Enums\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuoteItemRequest;
use App\Http\Resources\QuoteItemResource;
use App\Models\Product;
use App\Models\Quote;
use App\Models\TaxRate;
use App\Services\Sales\QuoteCalculator;

class QuoteItemController extends Controller
{
    public function __construct(private readonly QuoteCalculator $calculator) {}

    public function index($quoteId)
    {
        $quote = Quote::findOrFail($quoteId);

        $this->authorize('view', $quote);

        return QuoteItemResource::collection($quote->items()->get());
    }

    public function store(StoreQuoteItemRequest $request, $quoteId)
    {
        $quote = Quote::findOrFail($quoteId);

        $this->authorize('update', $quote);

        if (! in_array($quote->status, [QuoteStatus::Draft, QuoteStatus::Sent], true)) {
            return response()->json(['message' => 'No se pueden agregar productos a esta cotización en su estado actual.'], 422);
        }

        $product = Product::findOrFail($request->validated('product_id'));

        $taxRateId = $request->validated('tax_rate_id');
        $taxRate = $taxRateId !== null ? TaxRate::find($taxRateId) : null;

        $quantity = (float) $request->validated('quantity');
        $unitPrice = $request->validated('unit_price') !== null
            ? (float) $request->validated('unit_price')
            : (float) $product->precio_unitario;
        $discount = $request->validated('discount') !== null ? (float) $request->validated('discount') : 0.0;

        $calculated = $this->calculator->calculateItem($quantity, $unitPrice, $discount, $taxRate);

        $item = $quote->items()->create([
            'product_id' => $product->id,
            'tax_rate_id' => $taxRate?->id,
            'description' => $product->name,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            ...$calculated,
        ]);

        $this->calculator->recalculateQuote($quote)->save();

        return (new QuoteItemResource($item))->response()->setStatusCode(201);
    }

    public function destroy($quoteId, $itemId)
    {
        $quote = Quote::findOrFail($quoteId);

        $this->authorize('update', $quote);

        if (! in_array($quote->status, [QuoteStatus::Draft, QuoteStatus::Sent], true)) {
            return response()->json(['message' => 'No se pueden eliminar productos de esta cotización en su estado actual.'], 422);
        }

        $item = $quote->items()->findOrFail($itemId);

        $this->authorize('delete', $item);

        $item->delete();

        $this->calculator->recalculateQuote($quote)->save();

        return response()->json(null, 204);
    }
}
