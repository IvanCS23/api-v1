<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\TaxRate;
use App\Services\Taxes\LineTaxWriter;
use App\Services\Taxes\TaxSpec;
use App\Support\Sat\SatCatalog;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function __construct(private readonly LineTaxWriter $taxWriter) {}

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

        // Fase 6.34: impuestos del producto (product_taxes): `taxes` o, por
        // compatibilidad, `tax_rate_id`.
        $product = DB::transaction(function () use ($request): Product {
            $product = Product::create(Arr::except($request->validated(), ['taxes']));
            $this->syncTaxes($product, $request);

            return $product;
        });

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

        DB::transaction(function () use ($product, $request): void {
            $product->update(Arr::except($request->validated(), ['taxes']));
            $this->syncTaxes($product, $request);
        });

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

    /**
     * Fase 6.34: `taxes` reemplaza los impuestos del producto; `tax_rate_id`
     * (la pantalla actual) los reemplaza por esa tasa o por ninguno. Sin
     * ninguno de los dos, no se tocan.
     */
    private function syncTaxes(Product $product, StoreProductRequest|UpdateProductRequest $request): void
    {
        $specs = $request->taxSpecs();

        if ($specs === null && $request->exists('tax_rate_id')) {
            $taxRate = $product->tax_rate_id !== null ? TaxRate::find($product->tax_rate_id) : null;
            $specs = $taxRate !== null ? [TaxSpec::fromTaxRate($taxRate)] : [];
        }

        if ($specs !== null) {
            $this->taxWriter->replaceProductTaxes($product, $specs);
            $product->load('taxes');
        }
    }
}
