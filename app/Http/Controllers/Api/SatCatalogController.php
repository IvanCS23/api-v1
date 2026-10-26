<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Sat\SatCatalog;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Fase 6.26: catálogos SAT para los formularios de captura. Globales (no
 * por empresa), locales y sin llamadas al PAC (ver SatCatalog).
 */
class SatCatalogController extends Controller
{
    private const SEARCH_LIMIT = 20;

    /** Catálogos pequeños, incluida la matriz régimen ↔ uso ↔ tipo de persona. */
    public function index(): JsonResponse
    {
        return response()->json([
            'tax_regimes' => array_values(SatCatalog::taxRegimes()),
            'cfdi_uses' => array_values(SatCatalog::cfdiUses()),
            'payment_forms' => array_values(SatCatalog::paymentForms()),
            'tax_objects' => array_values(SatCatalog::taxObjects()),
        ]);
    }

    /** c_ClaveProdServ: por prefijo de clave (sólo dígitos) o por texto en la descripción. */
    public function productKeys(Request $request): JsonResponse
    {
        $q = $this->query($request);

        if ($q === '') {
            return response()->json([]);
        }

        $search = DB::table('sat_product_keys')->select(['code', 'description']);
        $this->onlyValid($search);

        if (ctype_digit($q)) {
            $search->where('code', 'like', $this->escapeLike($q).'%');
        } else {
            $like = '%'.$this->escapeLike($q).'%';
            $search->where(fn ($query) => $query->where('description', 'like', $like)->orWhere('similar_words', 'like', $like));
        }

        return response()->json($search->orderBy('code')->limit(self::SEARCH_LIMIT)->get());
    }

    /** c_ClaveUnidad: la clave exacta primero, luego prefijo de clave o texto en el nombre. */
    public function unitKeys(Request $request): JsonResponse
    {
        $q = $this->query($request);

        if ($q === '') {
            return response()->json([]);
        }

        $code = mb_strtoupper($q);
        $search = DB::table('sat_unit_keys')->select(['code', 'name', 'symbol']);
        $this->onlyValid($search);
        $search->where(fn ($query) => $query
            ->where('code', 'like', $this->escapeLike($code).'%')
            ->orWhere('name', 'like', '%'.$this->escapeLike($q).'%'));

        return response()->json(
            $search->orderByRaw('case when code = ? then 0 else 1 end', [$code])
                ->orderBy('code')
                ->limit(self::SEARCH_LIMIT)
                ->get(),
        );
    }

    private function query(Request $request): string
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        return trim((string) $request->query('q', ''));
    }

    private function onlyValid(Builder $query): void
    {
        $today = now()->toDateString();
        $query->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $today));
    }

    /**
     * Los comodines de LIKE se descartan en vez de escaparse: SQLite no tiene
     * carácter de escape por omisión y el catálogo no usa "%" ni "_".
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['%', '_'], '', $value);
    }
}
