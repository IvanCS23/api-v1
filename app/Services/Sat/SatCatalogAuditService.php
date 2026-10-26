<?php

namespace App\Services\Sat;

use App\Models\Client;
use App\Models\Product;
use App\Models\Scopes\CompanyScope;
use App\Support\Sat\SatCatalog;

/**
 * Fase 6.26: qué clientes y productos guardados ya no cumplen las reglas SAT
 * de captura (SatFiscalValidator). Sólo lee: no corrige nada, no escribe y
 * no consulta al PAC. Excluye registros borrados.
 *
 * Para clientes sólo se reportan códigos y campos (nunca el RFC ni otros
 * datos personales); para productos también los valores de catálogo, que no
 * son sensibles y ayudan a corregirlos.
 */
class SatCatalogAuditService
{
    public function __construct(private readonly SatFiscalValidator $validator) {}

    /**
     * @return array{
     *     clients: array{total: int, invalid: int, rows: list<array{id: int, company_id: int, codes: list<string>, fields: list<string>}>},
     *     products: array{total: int, invalid: int, rows: list<array{id: int, company_id: int, codes: list<string>, fields: list<string>, values: array<string, mixed>}>},
     *     by_code: array<string, int>
     * }
     */
    public function report(?int $companyId = null): array
    {
        $byCode = [];

        $clients = Client::withoutGlobalScope(CompanyScope::class)
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->orderBy('id')
            ->get(['id', 'company_id', 'rfc', 'codigo_postal', 'regimen_fiscal', 'uso_cfdi']);

        $clientRows = [];
        foreach ($clients as $client) {
            $issues = $this->validator->clientIssues($client->only(['rfc', 'codigo_postal', 'regimen_fiscal', 'uso_cfdi']));
            if ($issues !== []) {
                $clientRows[] = $this->row($client->id, $client->company_id, $issues, $byCode);
            }
        }

        $products = Product::withoutGlobalScope(CompanyScope::class)
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->orderBy('id')
            ->get(['id', 'company_id', 'clave_producto', 'clave_unidad', 'objeto_imp', 'tax_rate_id']);

        // Una consulta por catálogo para todo el reporte.
        $productKeys = SatCatalog::existingProductKeys($products->pluck('clave_producto'));
        $unitKeys = SatCatalog::existingUnitKeys($products->pluck('clave_unidad')->map(fn ($code) => mb_strtoupper(trim((string) $code))));

        $productRows = [];
        foreach ($products as $product) {
            $values = $product->only(['clave_producto', 'clave_unidad', 'objeto_imp', 'tax_rate_id']);
            $issues = $this->validator->productIssues(
                $values,
                isset($productKeys[trim((string) $product->clave_producto)]),
                isset($unitKeys[mb_strtoupper(trim((string) $product->clave_unidad))]),
            );
            if ($issues !== []) {
                $productRows[] = [...$this->row($product->id, $product->company_id, $issues, $byCode), 'values' => $values];
            }
        }

        ksort($byCode);

        return [
            'clients' => ['total' => $clients->count(), 'invalid' => count($clientRows), 'rows' => $clientRows],
            'products' => ['total' => $products->count(), 'invalid' => count($productRows), 'rows' => $productRows],
            'by_code' => $byCode,
        ];
    }

    /**
     * @param  list<array{code: string, field: string, message: string}>  $issues
     * @param  array<string, int>  $byCode
     * @return array{id: int, company_id: int, codes: list<string>, fields: list<string>}
     */
    private function row(int $id, int $companyId, array $issues, array &$byCode): array
    {
        $codes = array_values(array_unique(array_column($issues, 'code')));
        foreach ($codes as $code) {
            $byCode[$code] = ($byCode[$code] ?? 0) + 1;
        }

        return [
            'id' => $id,
            'company_id' => $companyId,
            'codes' => $codes,
            'fields' => array_values(array_unique(array_column($issues, 'field'))),
        ];
    }
}
