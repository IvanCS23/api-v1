<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Billing\InvoiceBillingResource;
use App\Models\Invoice;
use App\Services\Billing\LoadInvoiceBillingSnapshotService;
use Illuminate\Http\Request;

/**
 * Consulta fiscal exclusivamente local. No sincroniza ni resuelve PacProvider.
 */
class InvoiceBillingController extends Controller
{
    public function __construct(private readonly LoadInvoiceBillingSnapshotService $snapshots) {}

    public function __invoke(Request $request, int $invoice): InvoiceBillingResource
    {
        $model = Invoice::findOrFail($invoice);

        $this->authorize('view', $model);

        $limit = $this->timelineLimit($request);

        return new InvoiceBillingResource($this->snapshots->load($model, $limit));
    }

    private function timelineLimit(Request $request): int
    {
        $requested = filter_var(
            $request->query('timeline_limit'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if ($requested === false) {
            return LoadInvoiceBillingSnapshotService::DEFAULT_TIMELINE_LIMIT;
        }

        return min($requested, LoadInvoiceBillingSnapshotService::MAX_TIMELINE_LIMIT);
    }
}
