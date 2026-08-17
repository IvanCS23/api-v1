<?php

namespace App\Http\Controllers\Api;

use App\Enums\CfdiCancellationMotive;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelInvoiceBusinessRequest;
use App\Http\Requests\IssueInvoiceWithPacRequest;
use App\Http\Resources\Billing\InvoiceBillingResource;
use App\Models\Invoice;
use App\Services\Billing\LoadInvoiceBillingSnapshotService;
use App\Services\Billing\OrchestrateInvoiceCancellationService;
use App\Services\Billing\OrchestrateInvoiceIssuanceService;
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
    ) {}

    public function issue(IssueInvoiceWithPacRequest $request, int $invoice): InvoiceBillingResource|JsonResponse
    {
        $model = Invoice::findOrFail($invoice);
        $this->authorize('issueBusiness', $model);

        try {
            $updated = $this->issuance->issue($model);
        } catch (Throwable $error) {
            return $this->expectedErrorOrRethrow($error);
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
            return $this->expectedErrorOrRethrow($error);
        }

        $resource = new InvoiceBillingResource($this->snapshots->load($updated));

        return $updated->status === InvoiceStatus::Cancelled
            ? $resource
            : $resource->response()->setStatusCode(202);
    }

    private function expectedErrorOrRethrow(Throwable $error): JsonResponse
    {
        $response = $this->errors->respond($error);

        if ($response !== null) {
            return $response;
        }

        throw $error;
    }
}
