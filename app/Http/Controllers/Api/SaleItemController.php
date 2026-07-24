<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSaleItemRequest;
use App\Http\Requests\UpdateSaleItemRequest;
use App\Http\Resources\SaleItemResource;
use App\Models\Product;
use App\Models\Sale;
use App\Models\TaxRate;
use App\Services\Sales\SaleCalculator;
use Illuminate\Support\Facades\DB;

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

        if (! $sale->isEditable()) {
            return response()->json(['message' => 'No se pueden agregar productos a esta venta en su estado actual.'], 422);
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

    public function update(UpdateSaleItemRequest $request, $saleId, $itemId)
    {
        $sale = Sale::findOrFail($saleId);

        $this->authorize('update', $sale);

        if (! $sale->isEditable()) {
            return response()->json(['message' => 'No se pueden modificar productos de esta venta en su estado actual.'], 422);
        }

        $item = $sale->items()->findOrFail($itemId);

        $this->authorize('update', $item);

        $data = $request->validated();

        DB::transaction(function () use ($data, $item, $sale): void {
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

            $this->calculator->recalculateSale($sale)->save();
        });

        return new SaleItemResource($item);
    }

    public function destroy($saleId, $itemId)
    {
        $sale = Sale::findOrFail($saleId);

        $this->authorize('update', $sale);

        if (! $sale->isEditable()) {
            return response()->json(['message' => 'No se pueden eliminar productos de esta venta en su estado actual.'], 422);
        }

        $item = $sale->items()->findOrFail($itemId);

        $this->authorize('delete', $item);

        $item->delete();

        $this->calculator->recalculateSale($sale)->save();

        return response()->json(null, 204);
    }
}
