<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Billing\PacProviderResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\UpdatePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Payments\InvoiceBalance;
use App\Services\Payments\IssuePaymentService;
use App\Services\Payments\PaymentIssuanceInProgressException;
use App\Services\Payments\PaymentNotDraftException;
use App\Services\Payments\PaymentNotReadyException;
use App\Services\Payments\PaymentReceiptNotValidException;
use App\Services\Payments\PaymentRegistrar;
use App\Services\Payments\PaymentSummaryMismatchException;
use App\Support\Billing\InvoicePacActionExceptionResponder;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Fase 6.35 (2): pagos recibidos de facturas PPD (registro en borrador).
 * La emisión y cancelación del REP llegan en los commits siguientes.
 */
class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentRegistrar $registrar,
        private readonly InvoiceBalance $balance,
        private readonly IssuePaymentService $issuer,
        private readonly PacProviderResolver $resolver,
        private readonly InvoicePacActionExceptionResponder $responder,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Payment::class);

        $filters = $request->validate([
            'client_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['draft', 'issued', 'cancelled'])],
            'search' => ['nullable', 'string', 'max:150'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 20, 50, 100])],
        ]);

        $query = Payment::query()->with('client:id,company_id,name,rfc')
            ->when($filters['client_id'] ?? null, fn ($query, $client) => $query->where('client_id', $client))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, function ($query, $search): void {
                $like = '%'.$search.'%';
                $query->where(fn ($query) => $query->where('folio', 'like', $like)
                    ->orWhere('cfdi_uuid', 'like', $like)
                    ->orWhereHas('client', fn ($client) => $client->where(
                        fn ($client) => $client->where('name', 'like', $like)->orWhere('rfc', 'like', $like),
                    )));
            });

        return PaymentResource::collection(
            $query->orderByDesc('payment_date')->orderByDesc('id')->paginate($filters['per_page'] ?? 20)->withQueryString(),
        );
    }

    /**
     * Facturas del cliente que admiten pagos, con su saldo. `payment_id`
     * (al editar un borrador) no descuenta lo que ese mismo pago reserva.
     */
    public function receivableInvoices(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        $companyId = app(CurrentTenant::class)->id();
        $filters = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('company_id', $companyId)],
            'payment_id' => ['nullable', 'integer'],
        ]);
        $exceptPayment = isset($filters['payment_id']) ? (int) $filters['payment_id'] : null;

        $rows = PaymentRegistrar::receivableInvoices()
            ->where('client_id', $filters['client_id'])
            ->orderBy('issued_at')->orderBy('id')
            ->get()
            ->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'folio' => $invoice->folio,
                'cfdi_uuid' => $invoice->cfdi_uuid,
                'issued_at' => $invoice->issued_at?->toIso8601String(),
                'currency' => $invoice->currency ?? 'MXN',
                'total' => $invoice->total,
                'balance' => $this->balance->fiscal($invoice),
                'available' => $this->balance->available($invoice, $exceptPayment),
                'issued_payments' => $this->balance->issuedCount($invoice),
            ])
            ->filter(fn (array $row) => bccomp($row['balance'], '0', 6) > 0)
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function store(StorePaymentRequest $request): JsonResponse
    {
        $this->authorize('create', Payment::class);

        $payment = $this->registrar->create($request->validated(), $request->user(), app(CurrentTenant::class)->id());

        return (new PaymentResource($this->loaded($payment)))->response()->setStatusCode(201);
    }

    public function show($id): PaymentResource
    {
        $payment = Payment::findOrFail($id);
        $this->authorize('view', $payment);

        return new PaymentResource($this->loaded($payment));
    }

    public function update(UpdatePaymentRequest $request, $id): JsonResponse|PaymentResource
    {
        $payment = Payment::findOrFail($id);
        $this->authorize('update', $payment);

        try {
            $payment = $this->registrar->update($payment, $request->validated());
        } catch (PaymentNotDraftException $e) {
            return $this->notDraft($e);
        }

        return new PaymentResource($this->loaded($payment));
    }

    public function destroy($id): JsonResponse
    {
        $payment = Payment::findOrFail($id);
        $this->authorize('delete', $payment);

        try {
            $this->registrar->delete($payment);
        } catch (PaymentNotDraftException $e) {
            return $this->notDraft($e);
        }

        return response()->json(null, 204);
    }

    /** Fase 6.35 (4): emite el REP del pago en borrador. */
    public function issue(Request $request, $id): JsonResponse|PaymentResource
    {
        $payment = Payment::findOrFail($id);
        $this->authorize('update', $payment);

        try {
            $payment = $this->issuer->issue($payment, $request->user());
        } catch (PaymentNotDraftException $e) {
            return $this->notDraft($e);
        } catch (PaymentNotReadyException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => PaymentNotReadyException::CODE, 'errors' => $e->problems], 422);
        } catch (PaymentSummaryMismatchException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => PaymentSummaryMismatchException::CODE, 'differences' => $e->differences], 422);
        } catch (PaymentIssuanceInProgressException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => PaymentIssuanceInProgressException::CODE], 409);
        } catch (PaymentReceiptNotValidException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => PaymentReceiptNotValidException::CODE], 502);
        } catch (Throwable $e) {
            return $this->responder->respond($e, null, 'issuePayment') ?? throw $e;
        }

        return new PaymentResource($this->loaded($payment));
    }

    public function cfdiXml($id): Response|JsonResponse
    {
        return $this->artifact($id, 'xml');
    }

    public function cfdiPdf($id): Response|JsonResponse
    {
        return $this->artifact($id, 'pdf');
    }

    /** XML o PDF del REP, directo del PAC (no se guarda copia todavía). */
    private function artifact($id, string $format): Response|JsonResponse
    {
        $payment = Payment::findOrFail($id);
        $this->authorize('view', $payment);

        if (blank($payment->pac_external_id)) {
            return response()->json(['message' => 'El pago todavía no tiene complemento de pago emitido.', 'code' => 'PAYMENT_NOT_ISSUED'], 404);
        }

        try {
            $provider = $this->resolver->forCompany((int) $payment->company_id);
            $content = $format === 'xml' ? $provider->downloadXml($payment->pac_external_id) : $provider->downloadPdf($payment->pac_external_id);
        } catch (Throwable $e) {
            return $this->responder->respond($e, null, 'downloadPayment') ?? throw $e;
        }

        return response($content, 200, [
            'Content-Type' => $format === 'xml' ? 'application/xml' : 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$payment->folio}.{$format}\"",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function loaded(Payment $payment): Payment
    {
        return $payment->fresh(['client:id,company_id,name,rfc', 'allocations.invoice:id,folio,cfdi_uuid']);
    }

    private function notDraft(PaymentNotDraftException $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage(), 'code' => PaymentNotDraftException::CODE], 409);
    }
}
