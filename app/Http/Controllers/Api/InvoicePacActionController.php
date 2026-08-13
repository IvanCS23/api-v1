<?php

namespace App\Http\Controllers\Api;

use App\Enums\CfdiCancellationMotive;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelInvoiceWithPacRequest;
use App\Http\Requests\IssueInvoiceWithPacRequest;
use App\Http\Resources\Billing\InvoiceBillingResource;
use App\Models\Invoice;
use App\Services\Billing\DownloadCancellationReceiptService;
use App\Services\Billing\DownloadInvoiceArtifactsService;
use App\Services\Billing\InvoiceWorkflow;
use App\Services\Billing\IssueInvoiceToPacService;
use App\Services\Billing\LoadInvoiceBillingSnapshotService;
use App\Support\Billing\InvoicePacActionExceptionResponder;
use Closure;
use Illuminate\Http\JsonResponse;
use Throwable;

class InvoicePacActionController extends Controller
{
    public function __construct(
        private readonly InvoiceWorkflow $workflow,
        private readonly IssueInvoiceToPacService $issueInvoice,
        private readonly DownloadInvoiceArtifactsService $artifacts,
        private readonly DownloadCancellationReceiptService $receipts,
        private readonly LoadInvoiceBillingSnapshotService $snapshots,
        private readonly InvoicePacActionExceptionResponder $errors,
    ) {}

    public function issue(IssueInvoiceWithPacRequest $request, int $invoice): InvoiceBillingResource|JsonResponse
    {
        return $this->perform($invoice, 'issuePac', function (Invoice $model): void {
            $this->issueInvoice->issue($model);
        });
    }

    public function reconcile(int $invoice): InvoiceBillingResource|JsonResponse
    {
        return $this->perform($invoice, 'reconcilePac', function (Invoice $model): void {
            $this->workflow->reconcileWithPac($model, throwOnFailure: true);
        });
    }

    public function artifacts(int $invoice): InvoiceBillingResource|JsonResponse
    {
        return $this->perform($invoice, 'managePacArtifacts', function (Invoice $model): void {
            $this->artifacts->download($model);
        });
    }

    public function cancel(CancelInvoiceWithPacRequest $request, int $invoice): InvoiceBillingResource|JsonResponse
    {
        return $this->perform($invoice, 'cancelPac', function (Invoice $model) use ($request): void {
            $this->workflow->cancelWithPac(
                $model,
                CfdiCancellationMotive::from((string) $request->validated('motive')),
                $request->validated('substitution_uuid'),
            );
        });
    }

    public function cancellationReceipt(int $invoice): InvoiceBillingResource|JsonResponse
    {
        return $this->perform($invoice, 'managePacArtifacts', function (Invoice $model): void {
            $this->receipts->download($model);
        });
    }

    private function perform(int $invoice, string $ability, Closure $action): InvoiceBillingResource|JsonResponse
    {
        $model = Invoice::findOrFail($invoice);
        $this->authorize($ability, $model);

        try {
            $action($model);
        } catch (Throwable $error) {
            $response = $this->errors->respond($error);

            if ($response !== null) {
                return $response;
            }

            throw $error;
        }

        $fresh = Invoice::findOrFail($model->id);

        return new InvoiceBillingResource($this->snapshots->load($fresh));
    }
}
