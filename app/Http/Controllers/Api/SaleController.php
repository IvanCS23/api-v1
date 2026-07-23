<?php

namespace App\Http\Controllers\Api;

use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSaleRequest;
use App\Http\Requests\UpdateSaleRequest;
use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Services\Sales\SaleNumberGenerator;
use App\Support\Tenant\CurrentTenant;

class SaleController extends Controller
{
    public function __construct(private readonly SaleNumberGenerator $numberGenerator) {}

    public function index()
    {
        $this->authorize('viewAny', Sale::class);

        return SaleResource::collection(Sale::with('items')->latest()->get());
    }

    public function store(StoreSaleRequest $request)
    {
        $this->authorize('create', Sale::class);

        $companyId = app(CurrentTenant::class)->id();

        $sale = Sale::create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
            'folio' => $this->numberGenerator->next($companyId),
            'status' => SaleStatus::Draft,
        ]);

        return (new SaleResource($sale->load('items')))->response()->setStatusCode(201);
    }

    public function show($id)
    {
        $sale = Sale::with('items')->findOrFail($id);

        $this->authorize('view', $sale);

        return new SaleResource($sale);
    }

    public function update(UpdateSaleRequest $request, $id)
    {
        $sale = Sale::findOrFail($id);

        $this->authorize('update', $sale);

        if ($sale->status === SaleStatus::Cancelled) {
            return response()->json(['message' => 'No se puede modificar una venta cancelada.'], 422);
        }

        $sale->update($request->validated());

        return new SaleResource($sale->load('items'));
    }

    public function destroy($id)
    {
        $sale = Sale::findOrFail($id);

        $this->authorize('delete', $sale);

        $sale->delete();

        return response()->json(null, 204);
    }
}
