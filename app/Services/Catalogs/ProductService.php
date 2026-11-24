<?php

namespace App\Services\Catalogs;

use App\Models\Product;
use App\Models\TaxRate;
use App\Services\Taxes\LineTaxWriter;
use App\Services\Taxes\TaxSpec;
use App\Support\Sat\SatCatalog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Lógica de negocio de productos: CRUD, impuestos del producto
 * (product_taxes) y verificación de claves SAT para el listado.
 *
 * La autorización (policies) NO se hace aquí: es responsabilidad de la
 * capa HTTP. El servicio tampoco depende del Request: el controlador le
 * pasa los datos ya validados.
 */
class ProductService
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    public function __construct(private readonly LineTaxWriter $taxWriter) {}

    public function all(): Collection
    {
        return Product::all();
    }

    public function paginated(int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        // Orden explícito para que las páginas sean estables entre peticiones.
        return Product::query()
            ->orderBy((new Product)->getKeyName())
            ->paginate(perPage: $perPage, page: $page);
    }

    /**
     * @throws ModelNotFoundException
     */
    public function get(int|string $id): Product
    {
        return Product::findOrFail($id);
    }

    public function create(array $data, ?array $taxSpecs = null, bool $taxRateIdProvided = false): Product
    {
        return DB::transaction(function () use ($data, $taxSpecs, $taxRateIdProvided): Product {
            $product = Product::create(Arr::except($data, ['taxes']));
            $this->syncTaxes($product, $taxSpecs, $taxRateIdProvided);

            return $product;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<TaxSpec>|null  $taxSpecs
     */
    public function update(Product $product, array $data, ?array $taxSpecs = null, bool $taxRateIdProvided = false): Product
    {
        DB::transaction(function () use ($product, $data, $taxSpecs, $taxRateIdProvided): void {
            $product->update(Arr::except($data, ['taxes']));
            $this->syncTaxes($product, $taxSpecs, $taxRateIdProvided);
        });

        return $product;
    }

    public function delete(Product $product): void
    {
        $product->delete();
    }

    /**
     * Comprueba la existencia de claves SAT en una consulta por catálogo
     * para calcular `fiscal_issues` de todo el listado (o de la página).
     *
     * @param  iterable<Product>  $products
     * @return array{0: mixed, 1: mixed} [claves de producto existentes, claves de unidad existentes]
     */
    public function catalogLookup(iterable $products): array
    {
        $products = collect($products);

        $productKeys = SatCatalog::existingProductKeys(
            $products->pluck('clave_producto')->map(fn ($code) => trim((string) $code))
        );
        $unitKeys = SatCatalog::existingUnitKeys(
            $products->pluck('clave_unidad')->map(fn ($code) => mb_strtoupper(trim((string) $code)))
        );

        return [$productKeys, $unitKeys];
    }

    /**
     * Fase 6.34: `taxes` reemplaza los impuestos del producto; `tax_rate_id`
     * (la pantalla actual) los reemplaza por esa tasa o por ninguno. Sin
     * ninguno de los dos, no se tocan.
     *
     * @param  list<TaxSpec>|null  $specs
     */
    private function syncTaxes(Product $product, ?array $specs, bool $taxRateIdProvided): void
    {
        if ($specs === null && $taxRateIdProvided) {
            $taxRate = $product->tax_rate_id !== null ? TaxRate::find($product->tax_rate_id) : null;
            $specs = $taxRate !== null ? [TaxSpec::fromTaxRate($taxRate)] : [];
        }

        if ($specs !== null) {
            $this->taxWriter->replaceProductTaxes($product, $specs);
            $product->load('taxes');
        }
    }
}
