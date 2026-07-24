<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuoteItemRequest;
use App\Http\Requests\UpdateQuoteItemRequest;
use App\Http\Resources\QuoteItemResource;
use App\Models\Product;
use App\Models\Quote;
use App\Models\TaxRate;
use App\Services\Sales\QuoteCalculator;
use Illuminate\Support\Facades\DB;

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

        if (! $quote->isEditable()) {
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

    public function update(UpdateQuoteItemRequest $request, $quoteId, $itemId)
    {
        $quote = Quote::findOrFail($quoteId);

        $this->authorize('update', $quote);

        if (! $quote->isEditable()) {
            return response()->json(['message' => 'No se pueden modificar productos de esta cotización en su estado actual.'], 422);
        }

        $item = $quote->items()->findOrFail($itemId);

        $this->authorize('update', $item);

        $data = $request->validated();

        DB::transaction(function () use ($data, $item, $quote): void {
            if (array_key_exists('product_id', $data)) {
                $item->product_id = $data['product_id'];
            }
            if (array_key_exists('description', $data)) {
                $item->description = $data['description'];
            }

            $quantity = array_key_exists('quantity', $data) ? (float) $data['quantity'] : (float) $item->quantity;
            $unitPrice = array_key_exists('unit_price', $data) ? (float) $data['unit_price'] : (float) $item->unit_price;
            $discount = array_key_exists('discount', $data) ? (float) $data['discount'] : (float) $item->discount;
            $taxRateId = array_key_exists('tax_rate_id', $data) ? $data['tax_rate_id'] : $item->tax_rate_id;
            $taxRate = $taxRateId !== null ? TaxRate::find($taxRateId) : null;

            $calculated = $this->calculator->calculateItem($quantity, $unitPrice, $discount, $taxRate);

            $item->fill([
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'tax_rate_id' => $taxRateId,
                ...$calculated,
            ]);
            $item->save();

            $this->calculator->recalculateQuote($quote)->save();
        });

        return new QuoteItemResource($item);
    }

    public function destroy($quoteId, $itemId)
    {
        $quote = Quote::findOrFail($quoteId);

        $this->authorize('update', $quote);

        if (! $quote->isEditable()) {
            return response()->json(['message' => 'No se pueden eliminar productos de esta cotización en su estado actual.'], 422);
        }

        $item = $quote->items()->findOrFail($itemId);

        $this->authorize('delete', $item);

        $item->delete();

        $this->calculator->recalculateQuote($quote)->save();

        return response()->json(null, 204);
    }
}
