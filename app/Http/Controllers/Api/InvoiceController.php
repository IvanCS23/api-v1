<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SaleAlreadyInvoicedException;
use App\Exceptions\SaleNotBillableException;
use App\Exceptions\WorkflowTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexInvoiceRequest;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\Sale;
use App\Services\Billing\InvoiceWorkflow;
use App\Services\Billing\SaleToInvoiceConverter;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly SaleToInvoiceConverter $converter,
        private readonly InvoiceWorkflow $workflow,
    ) {}

    public function index(IndexInvoiceRequest $request)
    {
        $this->authorize('viewAny', Invoice::class);

        $filters = $request->validated();
        $query = Invoice::query()->select([
            'id', 'client_id', 'folio', 'status', 'client_name', 'client_rfc',
            'client_regimen_fiscal', 'client_uso_cfdi', 'client_codigo_postal',
            'subtotal', 'discount_total', 'tax_total', 'total', 'currency',
            'notes', 'payment_form', 'payment_method', 'issued_at',
            'cancelled_at', 'created_at', 'updated_at',
        ]);

        if (isset($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(function ($query) use ($search): void {
                $query->where('folio', 'like', $search)
                    ->orWhere('client_name', 'like', $search)
                    ->orWhere('client_rfc', 'like', $search);
            });
        }

        $query->when(
            $filters['status'] ?? null,
            fn ($query, $status) => $query->where('status', $status),
        )->when(
            $filters['payment_method'] ?? null,
            fn ($query, $paymentMethod) => $query->where('payment_method', $paymentMethod),
        );

        // Fecha efectiva: issued_at cuando existe; created_at solo para
        // facturas aún no emitidas. No se mezclan ambas fechas en emitidas.
        foreach (['date_from' => '>=', 'date_to' => '<='] as $filter => $operator) {
            if (! isset($filters[$filter])) {
                continue;
            }

            $date = $filters[$filter];
            $query->where(function ($query) use ($date, $operator): void {
                $query->whereDate('issued_at', $operator, $date)
                    ->orWhere(function ($query) use ($date, $operator): void {
                        $query->whereNull('issued_at')->whereDate('created_at', $operator, $date);
                    });
            });
        }

        $sort = $filters['sort'] ?? 'created_at';
        $direction = $filters['direction'] ?? 'desc';
        $query->orderBy($sort, $direction)->orderBy('id', $direction);

        return InvoiceResource::collection(
            $query->paginate($filters['per_page'] ?? 20)->withQueryString(),
        );
    }

    /**
     * Crea una Invoice a partir de una Sale (`sale_id`). Toda la lógica
     * de negocio (billing-readiness, snapshot fiscal, doble conversión)
     * vive en SaleToInvoiceConverter — este controller solo traduce sus
     * excepciones a respuestas HTTP.
     */
    public function store(StoreInvoiceRequest $request)
    {
        $this->authorize('create', Invoice::class);

        $sale = Sale::findOrFail($request->validated('sale_id'));

        try {
            $invoice = $this->converter->convert($sale, $request->user()->id);
        } catch (SaleAlreadyInvoicedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (SaleNotBillableException $e) {
            return response()->json([
                'message' => 'La venta no está lista para facturarse.',
                'errors' => $e->errors,
            ], 422);
        }

        return (new InvoiceResource($invoice->load('items')))->response()->setStatusCode(201);
    }

    public function show($id)
    {
        $invoice = Invoice::with('items')->findOrFail($id);

        $this->authorize('view', $invoice);

        return new InvoiceResource($invoice);
    }

    public function update(UpdateInvoiceRequest $request, $id)
    {
        $invoice = Invoice::findOrFail($id);

        $this->authorize('update', $invoice);

        if (! $invoice->isEditable()) {
            return response()->json(['message' => 'Esta factura ya no se puede editar en su estado actual.'], 422);
        }

        $invoice->update($request->validated());

        return new InvoiceResource($invoice->load('items'));
    }

    public function destroy($id)
    {
        $invoice = Invoice::findOrFail($id);

        $this->authorize('delete', $invoice);

        if (! $invoice->isEditable()) {
            return response()->json(['message' => 'Esta factura ya no se puede eliminar en su estado actual.'], 422);
        }

        $invoice->delete();

        return response()->json(null, 204);
    }

    public function ready($id)
    {
        $invoice = Invoice::findOrFail($id);

        $this->authorize('update', $invoice);

        try {
            $invoice = $this->workflow->markReady($invoice);
        } catch (WorkflowTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new InvoiceResource($invoice->load('items'));
    }

    public function issue($id)
    {
        $invoice = Invoice::findOrFail($id);

        $this->authorize('update', $invoice);

        try {
            $invoice = $this->workflow->issue($invoice);
        } catch (WorkflowTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new InvoiceResource($invoice->load('items'));
    }

    public function cancel($id)
    {
        $invoice = Invoice::findOrFail($id);

        $this->authorize('update', $invoice);

        try {
            $invoice = $this->workflow->cancel($invoice);
        } catch (WorkflowTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new InvoiceResource($invoice->load('items'));
    }
}
