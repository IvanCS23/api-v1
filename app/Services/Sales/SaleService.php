<?php

namespace App\Services\Sales;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\User;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Lógica de negocio de ventas: listado con filtros y creación.
 * Las transiciones de estado viven en SaleWorkflow y la facturación en
 * InvoiceSaleService.
 *
 * La autorización (policies) NO se hace aquí: es responsabilidad de la
 * capa HTTP.
 */
class SaleService
{
    public const DEFAULT_PER_PAGE = 20;

    public function __construct(
        private readonly SaleNumberGenerator $numberGenerator,
        private readonly CurrentTenant $tenant,
    ) {}

    /**
     * Fase 6.25: paginado y con filtros. Cada fila trae el cliente y la
     * factura vigente.
     *
     * @param  array{search?: string, status?: string, per_page?: int}  $filters  Datos validados por IndexSaleRequest.
     */
    public function search(array $filters): LengthAwarePaginator
    {
        $query = Sale::query()->with(['client:id,company_id,name,rfc', 'invoice:id,sale_id,folio,status']);

        if (isset($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(function ($query) use ($search): void {
                $query->where('folio', 'like', $search)
                    ->orWhereHas('client', fn ($client) => $client->where(
                        fn ($client) => $client->where('name', 'like', $search)->orWhere('rfc', 'like', $search),
                    ));
            });
        }

        $query->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status));

        return $query->latest()
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? self::DEFAULT_PER_PAGE)
            ->withQueryString();
    }

    /**
     * Crea una venta en estado Draft con folio consecutivo del tenant.
     * Se envuelve en transacción para que folio y registro se confirmen juntos.
     *
     * @param  array<string, mixed>  $data  Datos validados.
     */
    public function create(array $data, User $author): Sale
    {
        $sale = DB::transaction(fn (): Sale => Sale::create([
            ...$data,
            'created_by' => $author->id,
            'folio' => $this->numberGenerator->next($this->tenant->id()),
            'status' => SaleStatus::Draft,
        ]));

        return $sale->load('items');
    }
}
