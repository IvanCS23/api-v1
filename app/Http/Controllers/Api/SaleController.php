<?php

namespace App\Http\Controllers\Api;

use App\Enums\SaleStatus;
use App\Exceptions\WorkflowTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSaleRequest;
use App\Http\Requests\UpdateSaleRequest;
use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Services\Billing\SaleBillingReadinessService;
use App\Services\Sales\SaleNumberGenerator;
use App\Services\Sales\SaleWorkflow;
use App\Support\Tenant\CurrentTenant;

class SaleController extends Controller
{
    public function __construct(
        private readonly SaleNumberGenerator $numberGenerator,
        private readonly SaleWorkflow $workflow,
        private readonly SaleBillingReadinessService $billingReadiness,
    ) {}

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

        if (! $sale->isEditable()) {
            return response()->json(['message' => 'Esta venta ya no se puede editar en su estado actual.'], 422);
        }

        $sale->update($request->validated());

        return new SaleResource($sale->load('items'));
    }

    public function destroy($id)
    {
        $sale = Sale::findOrFail($id);

        $this->authorize('delete', $sale);

        if (! $sale->isEditable()) {
            return response()->json(['message' => 'Esta venta ya no se puede eliminar en su estado actual.'], 422);
        }

        $sale->delete();

        return response()->json(null, 204);
    }

    public function submit($id)
    {
        $sale = Sale::findOrFail($id);

        $this->authorize('update', $sale);

        try {
            $sale = $this->workflow->submit($sale);
        } catch (WorkflowTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new SaleResource($sale->load('items'));
    }

    public function confirm($id)
    {
        $sale = Sale::findOrFail($id);

        $this->authorize('update', $sale);

        try {
            $sale = $this->workflow->confirm($sale);
        } catch (WorkflowTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new SaleResource($sale->load('items'));
    }

    public function cancel($id)
    {
        $sale = Sale::findOrFail($id);

        $this->authorize('update', $sale);

        try {
            $sale = $this->workflow->cancel($sale);
        } catch (WorkflowTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new SaleResource($sale->load('items'));
    }

    public function billingReadiness($id)
    {
        $sale = Sale::findOrFail($id);

        $this->authorize('view', $sale);

        return response()->json($this->billingReadiness->evaluate($sale));
    }
}
