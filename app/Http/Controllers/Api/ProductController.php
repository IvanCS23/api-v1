<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\Catalogs\ProductService;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(private readonly ProductService $products) {}

    /**
     * Obtener todos los productos
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Product::class);

        if ($request->has('page')) {
            $page = $this->products->paginated(
                (int) $request->query('page', 1),
                (int) $request->query('per_page', ProductService::DEFAULT_PER_PAGE),
            );

            // Fase 6.26: el lookup de claves SAT se hace solo sobre la página actual.
            [$productKeys, $unitKeys] = $this->products->catalogLookup($page->items());

            $collection = ProductResource::collection($page);
            $collection->collection = $collection->collection->map(
                fn (ProductResource $resource) => $resource->withCatalogLookup($productKeys, $unitKeys)
            );

            return $collection;
        }

        $products = $this->products->all();

        [$productKeys, $unitKeys] = $this->products->catalogLookup($products);

        return $products->map(fn (Product $product) => (new ProductResource($product))->withCatalogLookup($productKeys, $unitKeys))->values();
    }

    /**
     * Crear producto
     */
    public function store(StoreProductRequest $request)
    {
        $this->authorize('create', Product::class);

        $product = $this->products->create(
            $request->validated(),
            $request->taxSpecs(),
            $request->exists('tax_rate_id'),
        );

        return (new ProductResource($product))->response()->setStatusCode(201);
    }

    /**
     * Mostrar producto específico
     */
    public function show(string $id)
    {
        $product = $this->products->get($id);

        $this->authorize('view', $product);

        return new ProductResource($product);
    }

    /**
     * Actualizar producto
     */
    public function update(UpdateProductRequest $request, string $id)
    {
        $product = $this->products->get($id);

        $this->authorize('update', $product);

        $product = $this->products->update(
            $product,
            $request->validated(),
            $request->taxSpecs(),
            $request->exists('tax_rate_id'),
        );

        return new ProductResource($product);
    }

    /**
     * Eliminar producto
     */
    public function destroy(string $id)
    {
        $product = $this->products->get($id);

        $this->authorize('delete', $product);

        $this->products->delete($product);

        return response()->json([
            'message' => 'Producto eliminado correctamente',
        ]);
    }
}
