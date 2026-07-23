<?php

namespace App\Http\Controllers\Api;

use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSaleItemRequest;
use App\Http\Resources\SaleItemResource;
use App\Models\Product;
use App\Models\Sale;
use App\Models\TaxRate;
use App\Services\Sales\SaleCalculator;

class SaleItemController extends Controller
{
    public function __construct(private readonly SaleCalculator $calculator) {}

    public function index($saleId)
    {
        $sale = Sale::findOrFail($saleId);

        $this->authorize('view', $sale);

        return SaleItemResource::collection($sale->items()->get());
    }

    public function store(StoreSaleItemRequest $request, $saleId)
    {
        $sale = Sale::findOrFail($saleId);

        $this->authorize('update', $sale);

        if ($sale->status === SaleStatus::Cancelled) {
            return response()->json(['message' => 'No se pueden agregar productos a una venta cancelada.'], 422);
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

        $item = $sale->items()->create([
            'product_id' => $product->id,
            'tax_rate_id' => $taxRate?->id,
            'description' => $product->name,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            ...$calculated,
        ]);

        $this->calculator->recalculateSale($sale)->save();

        return (new SaleItemResource($item))->response()->setStatusCode(201);
    }

    public function destroy($saleId, $itemId)
    {
        $sale = Sale::findOrFail($saleId);

        $this->authorize('update', $sale);

        if ($sale->status === SaleStatus::Cancelled) {
            return response()->json(['message' => 'No se pueden eliminar productos de una venta cancelada.'], 422);
        }

        $item = $sale->items()->findOrFail($itemId);

        $this->authorize('delete', $item);

        $item->delete();

        $this->calculator->recalculateSale($sale)->save();

        return response()->json(null, 204);
    }
}
