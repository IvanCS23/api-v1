<?php

namespace App\Http\Controllers\Api;

use App\Enums\SaleStatus;
use App\Exceptions\SaleCancelledException;
use App\Exceptions\SaleNotBillableException;
use App\Exceptions\WorkflowTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexSaleRequest;
use App\Http\Requests\StoreSaleRequest;
use App\Http\Requests\UpdateSaleRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\SaleResource;
use App\Models\Invoice;
use App\Models\Sale;
use App\Services\Billing\SaleBillingReadinessService;
use App\Services\Sales\InvoiceSaleService;
use App\Services\Sales\SaleNumberGenerator;
use App\Services\Sales\SaleWorkflow;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    public function __construct(
        private readonly SaleNumberGenerator $numberGenerator,
        private readonly SaleWorkflow $workflow,
        private readonly SaleBillingReadinessService $billingReadiness,
        private readonly InvoiceSaleService $invoicing,
    ) {}

    /**
     * Fase 6.25: paginado y con filtros (antes devolvía todas las ventas
     * con sus líneas). Cada fila trae el cliente y la factura vigente.
     */
    public function index(IndexSaleRequest $request)
    {
        $this->authorize('viewAny', Sale::class);

        $filters = $request->validated();
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

        return SaleResource::collection(
            $query->latest()->orderByDesc('id')->paginate($filters['per_page'] ?? 20)->withQueryString(),
        );
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

        return new SaleResource($sale->load(['items', 'client:id,company_id,name,rfc', 'invoice:id,sale_id,folio,status']));
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

    /**
     * Fase 6.25: factura la venta en una sola operación (ver
     * InvoiceSaleService). 201 si crea la factura; 200 si ya existía
     * (idempotente, sin tocar una ready/issued/cancelled).
     */
    public function invoice(Request $request, $id)
    {
        $sale = Sale::findOrFail($id);

        $this->authorize('update', $sale);
        $this->authorize('create', Invoice::class);

        try {
            ['invoice' => $invoice, 'created' => $created] = $this->invoicing->invoice($sale, $request->user()->id);
        } catch (SaleNotBillableException $e) {
            return response()->json([
                'message' => 'La venta no está lista para facturarse.',
                'code' => 'SALE_NOT_BILLABLE',
                'errors' => $e->errors,
            ], 422);
        } catch (SaleCancelledException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'SALE_CANCELLED'], 409);
        } catch (WorkflowTransitionException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'SALE_WORKFLOW_CONFLICT'], 409);
        }

        return (new InvoiceResource($invoice->load('items')))
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }

    public function billingReadiness($id)
    {
        $sale = Sale::findOrFail($id);

        $this->authorize('view', $sale);

        // Fase 6.25: responde "¿se puede facturar ya?" en los términos de la
        // orquestación (POST /sales/{sale}/invoice), que envía y confirma.
        return response()->json($this->billingReadiness->evaluate($sale, beforeInvoicing: true));
    }
}
