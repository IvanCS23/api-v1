<?php

namespace App\Http\Controllers\Api;

use App\Enums\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuoteRequest;
use App\Http\Requests\UpdateQuoteRequest;
use App\Http\Resources\QuoteResource;
use App\Http\Resources\SaleResource;
use App\Models\Quote;
use App\Services\Sales\QuoteNumberGenerator;
use App\Services\Sales\QuoteToSaleConverter;
use App\Support\Tenant\CurrentTenant;

class QuoteController extends Controller
{
    public function __construct(
        private readonly QuoteNumberGenerator $numberGenerator,
        private readonly QuoteToSaleConverter $converter,
    ) {}

    public function index()
    {
        $this->authorize('viewAny', Quote::class);

        return QuoteResource::collection(Quote::with('items')->latest()->get());
    }

    public function store(StoreQuoteRequest $request)
    {
        $this->authorize('create', Quote::class);

        $companyId = app(CurrentTenant::class)->id();

        $quote = Quote::create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
            'folio' => $this->numberGenerator->next($companyId),
            'status' => QuoteStatus::Draft,
        ]);

        return (new QuoteResource($quote->load('items')))->response()->setStatusCode(201);
    }

    public function show($id)
    {
        $quote = Quote::with('items')->findOrFail($id);

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

        if (! in_array($quote->status, [QuoteStatus::Draft, QuoteStatus::Sent], true)) {
            return response()->json(['message' => 'Esta cotización ya no se puede editar en su estado actual.'], 422);
        }

        $quote->update($request->validated());

        return new QuoteResource($quote->load('items'));
    }

    public function destroy($id)
    {
        $quote = Quote::findOrFail($id);

        $this->authorize('delete', $quote);

        $quote->delete();

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

        $sale = $this->converter->convert($quote);

        return (new SaleResource($sale->load('items')))->response()->setStatusCode(201);
    }
}
