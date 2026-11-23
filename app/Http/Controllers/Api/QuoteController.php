<?php

namespace App\Http\Controllers\Api;

use App\Enums\QuoteStatus;
use App\Exceptions\QuoteAlreadyConvertedException;
use App\Exceptions\QuoteNotEditableException;
use App\Exceptions\WorkflowTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuoteRequest;
use App\Http\Requests\UpdateQuoteRequest;
use App\Http\Resources\QuoteResource;
use App\Http\Resources\SaleResource;
use App\Models\Quote;
use App\Services\Sales\QuoteService;
use App\Services\Sales\QuoteToSaleConverter;
use App\Services\Sales\QuoteWorkflow;
use Illuminate\Http\Request;

class QuoteController extends Controller
{
    public function __construct(
        private readonly QuoteService $quotes,
        private readonly QuoteToSaleConverter $converter,
        private readonly QuoteWorkflow $workflow,
    ) {}

    /**
     * Sin ?page devuelve el arreglo plano de siempre (no rompe al
     * frontend). Con ?page devuelve la respuesta paginada (data/links/meta);
     * ?per_page es opcional y QuoteService lo limita a 100.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Quote::class);

        $quotes = $request->has('page')
            ? $this->quotes->paginated(
                $request->integer('page', 1),
                $request->integer('per_page', QuoteService::DEFAULT_PER_PAGE),
            )
            : $this->quotes->all();

        return QuoteResource::collection($quotes);
    }

    public function store(StoreQuoteRequest $request)
    {
        $this->authorize('create', Quote::class);

        $quote = $this->quotes->create($request->validated(), $request->user());

        return (new QuoteResource($quote))->response()->setStatusCode(201);
    }

    public function show($id)
    {
        $quote = $this->quotes->get($id);

        $this->authorize('view', $quote);

        return new QuoteResource($quote);
    }

    /**
     * Draft y Sent son editables. Cualquier otro estado (Approved,
     * Rejected, Expired, Converted) es de solo lectura — la única
     * acción posible sobre una Approved es convert().
     */
    public function update(UpdateQuoteRequest $request, $id)
    {
        $quote = Quote::findOrFail($id);

        $this->authorize('update', $quote);

        try {
            $quote = $this->quotes->update($quote, $request->validated());
        } catch (QuoteNotEditableException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new QuoteResource($quote);
    }

    public function destroy($id)
    {
        $quote = Quote::findOrFail($id);

        $this->authorize('delete', $quote);

        try {
            $this->quotes->delete($quote);
        } catch (QuoteNotEditableException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(null, 204);
    }

    /**
     * Convierte una cotización Approved en una Sale nueva. La Quote
     * original no se modifica estructuralmente (ver QuoteToSaleConverter).
     */
    public function convert($id)
    {
        $quote = Quote::with('items')->findOrFail($id);

        $this->authorize('update', $quote);

        if ($quote->status !== QuoteStatus::Approved) {
            return response()->json(['message' => 'Solo una cotización aprobada puede convertirse en venta.'], 422);
        }

        try {
            $sale = $this->converter->convert($quote);
        } catch (QuoteAlreadyConvertedException) {
            return response()->json(['message' => 'Esta cotización ya fue convertida previamente.'], 422);
        }

        return (new SaleResource($sale->load('items')))->response()->setStatusCode(201);
    }

    public function send($id)
    {
        $quote = Quote::findOrFail($id);

        $this->authorize('update', $quote);

        try {
            $quote = $this->workflow->send($quote);
        } catch (WorkflowTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new QuoteResource($quote->load('items'));
    }

    public function approve($id)
    {
        $quote = Quote::findOrFail($id);

        $this->authorize('update', $quote);

        try {
            $quote = $this->workflow->approve($quote);
        } catch (WorkflowTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new QuoteResource($quote->load('items'));
    }

    public function reject($id)
    {
        $quote = Quote::findOrFail($id);

        $this->authorize('update', $quote);

        try {
            $quote = $this->workflow->reject($quote);
        } catch (WorkflowTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new QuoteResource($quote->load('items'));
    }

    public function expire($id)
    {
        $quote = Quote::findOrFail($id);

        $this->authorize('update', $quote);

        try {
            $quote = $this->workflow->expire($quote);
        } catch (WorkflowTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new QuoteResource($quote->load('items'));
    }
}
