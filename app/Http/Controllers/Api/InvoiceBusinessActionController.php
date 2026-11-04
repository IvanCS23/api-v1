<?php

namespace App\Http\Controllers\Api;

use App\Enums\CfdiCancellationMotive;
use App\Enums\InvoiceStatus;
use App\Exceptions\Billing\InvoiceEmailException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelInvoiceBusinessRequest;
use App\Http\Requests\IssueInvoiceWithPacRequest;
use App\Http\Requests\RevertInvoiceIssueRequest;
use App\Http\Requests\SendInvoiceEmailRequest;
use App\Http\Resources\Billing\InvoiceBillingResource;
use App\Models\Invoice;
use App\Services\Billing\LoadInvoiceBillingSnapshotService;
use App\Services\Billing\OrchestrateInvoiceCancellationService;
use App\Services\Billing\OrchestrateInvoiceIssuanceService;
use App\Services\Billing\RevertInvoiceIssueService;
use App\Services\Billing\SendInvoiceEmailService;
use App\Support\Billing\InvoiceEmailRecipients;
use App\Support\Billing\InvoicePacActionExceptionResponder;
use Illuminate\Http\JsonResponse;
use Throwable;

class InvoiceBusinessActionController extends Controller
{
    public function __construct(
        private readonly OrchestrateInvoiceIssuanceService $issuance,
        private readonly OrchestrateInvoiceCancellationService $cancellation,
        private readonly LoadInvoiceBillingSnapshotService $snapshots,
        private readonly InvoicePacActionExceptionResponder $errors,
        private readonly SendInvoiceEmailService $email,
        private readonly RevertInvoiceIssueService $revert,
    ) {}

    public function issue(IssueInvoiceWithPacRequest $request, int $invoice): InvoiceBillingResource|JsonResponse
    {
        $model = Invoice::findOrFail($invoice);
        $this->authorize('issueBusiness', $model);

        try {
            $updated = $this->issuance->issue($model);
        } catch (Throwable $error) {
            return $this->expectedErrorOrRethrow($error, $model, 'issueBusiness');
        }

        return new InvoiceBillingResource($this->snapshots->load($updated));
    }

    public function cancel(CancelInvoiceBusinessRequest $request, int $invoice): InvoiceBillingResource|JsonResponse
    {
        $model = Invoice::findOrFail($invoice);
        $this->authorize('cancelBusiness', $model);
        $motive = $request->validated('motive');

        try {
            $updated = $this->cancellation->cancel(
                $model,
                $motive !== null ? CfdiCancellationMotive::from((string) $motive) : null,
                $request->validated('substitution_uuid'),
            );
        } catch (Throwable $error) {
            return $this->expectedErrorOrRethrow($error, $model, 'cancelBusiness');
        }

        $resource = new InvoiceBillingResource($this->snapshots->load($updated));

        return $updated->status === InvoiceStatus::Cancelled
            ? $resource
            : $resource->response()->setStatusCode(202);
    }

    /** Fase 6.29: "Regresar a lista" (ver RevertInvoiceIssueService). */
    public function revertIssue(RevertInvoiceIssueRequest $request, int $invoice): InvoiceBillingResource|JsonResponse
    {
        $model = Invoice::findOrFail($invoice);
        $this->authorize('issueBusiness', $model);

        try {
            $updated = $this->revert->revert($model);
        } catch (Throwable $error) {
            return $this->expectedErrorOrRethrow($error, $model, 'revertIssue');
        }

        return new InvoiceBillingResource($this->snapshots->load($updated));
    }

    /**
     * Fase 6.30: envía (o reenvía) el CFDI por correo. Ventas sólo a los
     * destinatarios registrados del cliente; Admin y Contador pueden cambiar
     * la lista.
     */
    public function email(SendInvoiceEmailRequest $request, int $invoice): InvoiceBillingResource|JsonResponse
    {
        $model = Invoice::findOrFail($invoice);
        $this->authorize('sendEmail', $model);
        $recipients = $request->recipients();

        try {
            if ($recipients !== null
                && $request->user()->cannot('editEmailRecipients', $model)
                && ! InvoiceEmailRecipients::sameSet($recipients, $this->email->defaultRecipients($model))) {
                throw InvoiceEmailException::recipientsLocked();
            }

            $this->email->send($model, $recipients, $request->user(), SendInvoiceEmailService::TRIGGER_MANUAL);
        } catch (Throwable $error) {
            return $this->expectedErrorOrRethrow($error, $model, 'sendEmail');
        }

        return new InvoiceBillingResource($this->snapshots->load(Invoice::findOrFail($model->id)));
    }

    private function expectedErrorOrRethrow(Throwable $error, Invoice $invoice, string $action): JsonResponse
    {
        $response = $this->errors->respond($error, $invoice, $action);

        if ($response !== null) {
            return $response;
        }

        throw $error;
    }
}
