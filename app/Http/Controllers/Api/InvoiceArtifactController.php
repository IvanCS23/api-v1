<?php

namespace App\Http\Controllers\Api;

use App\Data\Billing\StoredInvoiceArtifact;
use App\Enums\InvoiceArtifactType;
use App\Exceptions\Billing\InvoiceArtifactIntegrityException;
use App\Exceptions\Billing\InvoiceArtifactUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Billing\ServeInvoiceArtifactService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceArtifactController extends Controller
{
    public function __construct(private readonly ServeInvoiceArtifactService $artifacts) {}

    public function cfdiXml(int $invoice): StreamedResponse|JsonResponse
    {
        return $this->serve($invoice, InvoiceArtifactType::CfdiXml);
    }

    public function cfdiPdf(int $invoice): StreamedResponse|JsonResponse
    {
        return $this->serve($invoice, InvoiceArtifactType::CfdiPdf);
    }

    public function cancellationReceiptXml(int $invoice): StreamedResponse|JsonResponse
    {
        return $this->serve($invoice, InvoiceArtifactType::CancellationReceiptXml);
    }

    public function cancellationReceiptPdf(int $invoice): StreamedResponse|JsonResponse
    {
        return $this->serve($invoice, InvoiceArtifactType::CancellationReceiptPdf);
    }

    private function serve(int $invoice, InvoiceArtifactType $type): StreamedResponse|JsonResponse
    {
        $model = Invoice::findOrFail($invoice);

        $this->authorize('view', $model);

        try {
            $artifact = $this->artifacts->get($model, $type);
        } catch (InvoiceArtifactUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 404, $this->securityHeaders());
        } catch (InvoiceArtifactIntegrityException $e) {
            return response()->json(['message' => $e->getMessage()], 409, $this->securityHeaders());
        }

        return $this->downloadResponse($artifact);
    }

    private function downloadResponse(StoredInvoiceArtifact $artifact): StreamedResponse
    {
        return response()->streamDownload(
            static function () use ($artifact): void {
                echo $artifact->contents;
            },
            $artifact->filename,
            $this->securityHeaders() + [
                'Content-Type' => $artifact->contentType,
                'Content-Length' => (string) strlen($artifact->contents),
            ],
        );
    }

    /** @return array<string, string> */
    private function securityHeaders(): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ];
    }
}
