<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSaleItemRequest;
use App\Http\Requests\UpdateSaleItemRequest;
use App\Http\Resources\SaleItemResource;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Scopes\CompanyScope;
use App\Models\TaxRate;
use App\Services\Sales\SaleCalculator;
use App\Services\Taxes\LineTaxWriter;
use App\Services\Taxes\TaxSpec;
use Illuminate\Support\Facades\DB;

class SaleItemController extends Controller
{
    public function __construct(
        private readonly SaleCalculator $calculator,
        private readonly LineTaxWriter $taxWriter,
    ) {}

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

        $product = Product::findOrFail($request->validated('product_id'));

        $taxRateId = $request->validated('tax_rate_id');
        $taxRate = $taxRateId !== null ? TaxRate::find($taxRateId) : null;

        $quantity = (float) $request->validated('quantity');
        $unitPrice = $request->validated('unit_price') !== null
            ? (float) $request->validated('unit_price')
            : (float) $product->precio_unitario;
        $discount = $request->validated('discount') !== null ? (float) $request->validated('discount') : 0.0;

        // Fase 6.34: `taxes`, o `tax_rate_id` (aunque sea nulo), o los del producto.
        $specs = $this->taxWriter->specsForNewLine($product, $request->taxSpecs(), $request->exists('tax_rate_id'), $taxRate);
        $calculated = $this->calculator->calculateLine($quantity, $unitPrice, $discount, $specs);
        $taxRows = $calculated['taxes'];
        unset($calculated['taxes']);

        $item = DB::transaction(function () use ($sale, $product, $taxRate, $quantity, $unitPrice, $calculated, $taxRows) {
            $locked = $this->lockedEditableSale($sale);

            if ($locked === null) {
                return null;
            }

            $item = $locked->items()->create([
                'product_id' => $product->id,
                'tax_rate_id' => $taxRate?->id,
                'description' => $product->name,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                ...$calculated,
            ]);
            $this->taxWriter->replace($item, $taxRows);

            $this->calculator->recalculateSale($locked)->save();

            return $item;
        });

        if ($item === null) {
            return response()->json(['message' => 'No se pueden agregar productos a esta venta en su estado actual.'], 422);
        }

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

        $updated = DB::transaction(function () use ($data, $item, $sale, $request): bool {
            $locked = $this->lockedEditableSale($sale);

            if ($locked === null) {
                return false;
            }

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

            // Fase 6.34: `taxes` o `tax_rate_id` de la petición; si no trae
            // ninguno, los impuestos que ya tiene la línea (o su tasa de antes).
            if (array_key_exists('taxes', $data)) {
                $specs = $request->taxSpecs() ?? [];
                $taxRateId = null;
            } elseif (array_key_exists('tax_rate_id', $data)) {
                $specs = $taxRate !== null ? [TaxSpec::fromTaxRate($taxRate)] : [];
            } else {
                $specs = $item->taxes->isNotEmpty() ? TaxSpec::fromModels($item->taxes) : ($taxRate !== null ? [TaxSpec::fromTaxRate($taxRate)] : []);
            }

            $calculated = $this->calculator->calculateLine($quantity, $unitPrice, $discount, $specs);
            $taxRows = $calculated['taxes'];
            unset($calculated['taxes']);

            $item->fill([
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'tax_rate_id' => $taxRateId,
                ...$calculated,
            ]);
            $item->save();
            $this->taxWriter->replace($item, $taxRows);

            $this->calculator->recalculateSale($locked)->save();

            return true;
        });

        if (! $updated) {
            return response()->json(['message' => 'No se pueden modificar productos de esta venta en su estado actual.'], 422);
        }

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

        $deleted = DB::transaction(function () use ($item, $sale): bool {
            $locked = $this->lockedEditableSale($sale);

            if ($locked === null) {
                return false;
            }

            $item->delete();
            $this->calculator->recalculateSale($locked)->save();

            return true;
        });

        if (! $deleted) {
            return response()->json(['message' => 'No se pueden eliminar productos de esta venta en su estado actual.'], 422);
        }

        return response()->json(null, 204);
    }

    /**
     * Fase 6.25: relee la venta con lockForUpdate (mismo criterio que
     * SaleWorkflow::lockedSale) y la devuelve sólo si sigue siendo
     * editable. Así una línea nunca se cuela mientras la orquestación
     * (POST /sales/{sale}/invoice) confirma y copia los conceptos: o entra
     * antes y se factura, o llega después y se rechaza.
     */
    private function lockedEditableSale(Sale $sale): ?Sale
    {
        $locked = Sale::withoutGlobalScope(CompanyScope::class)
            ->whereKey($sale->getKey())
            ->where('company_id', $sale->company_id)
            ->lockForUpdate()
            ->firstOrFail();

        return $locked->isEditable() ? $locked : null;
    }
}
