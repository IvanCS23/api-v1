<?php

namespace App\Http\Resources;

use App\Services\Sat\SatFiscalValidator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ver ClientResource: mismo shape plano que el contrato actual más
 * `fiscal_issues` (Fase 6.26). Para listados, withCatalogLookup() recibe la
 * existencia de claves ya consultada por lotes (SatCatalog::existing*), así
 * que el índice no hace una consulta por producto.
 */
class ProductResource extends JsonResource
{
    /** @var array<string, true>|null */
    private ?array $productKeys = null;

    /** @var array<string, true>|null */
    private ?array $unitKeys = null;

    /**
     * @param  array<string, true>  $productKeys
     * @param  array<string, true>  $unitKeys
     */
    public function withCatalogLookup(array $productKeys, array $unitKeys): static
    {
        $this->productKeys = $productKeys;
        $this->unitKeys = $unitKeys;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $productKey = trim((string) $this->resource->clave_producto);
        $unitKey = mb_strtoupper(trim((string) $this->resource->clave_unidad));

        return [
            ...parent::toArray($request),
            'fiscal_issues' => app(SatFiscalValidator::class)->productIssues(
                $this->resource->only(['clave_producto', 'clave_unidad', 'objeto_imp', 'tax_rate_id']),
                $this->productKeys === null ? null : isset($this->productKeys[$productKey]),
                $this->unitKeys === null ? null : isset($this->unitKeys[$unitKey]),
            ),
        ];
    }
}
