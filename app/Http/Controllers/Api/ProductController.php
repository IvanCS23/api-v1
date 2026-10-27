<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Support\Sat\SatCatalog;

class ProductController extends Controller
{
    /**
     * Obtener todos los productos
     */
    public function index()
    {
        $this->authorize('viewAny', Product::class);

        $products = Product::all();

        // Fase 6.26: existencia de claves SAT en una consulta por catálogo
        // para calcular `fiscal_issues` de todo el listado.
        $productKeys = SatCatalog::existingProductKeys($products->pluck('clave_producto')->map(fn ($code) => trim((string) $code)));
        $unitKeys = SatCatalog::existingUnitKeys($products->pluck('clave_unidad')->map(fn ($code) => mb_strtoupper(trim((string) $code))));

        return $products->map(fn (Product $product) => (new ProductResource($product))->withCatalogLookup($productKeys, $unitKeys))->values();
    }

    /**
     * Crear producto
     */
    public function store(StoreProductRequest $request)
    {
        $this->authorize('create', Product::class);

        $product = Product::create($request->validated());

        return (new ProductResource($product))->response()->setStatusCode(201);
    }

    /**
     * Mostrar producto específico
     */
    public function show(string $id)
    {
        $product = Product::findOrFail($id);

        $this->authorize('view', $product);

        return new ProductResource($product);
    }

    /**
     * Actualizar producto
     */
    public function update(UpdateProductRequest $request, string $id)
    {
        $product = Product::findOrFail($id);

        $this->authorize('update', $product);

        $product->update($request->validated());

        return new ProductResource($product);
    }

    /**
     * Eliminar producto
     */
    public function destroy(string $id)
    {
        $product = Product::findOrFail($id);

        $this->authorize('delete', $product);

        $product->delete();

        return response()->json([
            'message' => 'Producto eliminado correctamente',
        ]);
    }
}
