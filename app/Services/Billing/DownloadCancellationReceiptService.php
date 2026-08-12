<?php

namespace App\Services\Billing;

use App\Contracts\Billing\PacProvider;
use App\Data\Billing\CancellationReceiptResult;
use App\Enums\InvoicePacEventType;
use App\Exceptions\Billing\CancellationReceiptArtifactMissingException;
use App\Exceptions\Billing\CancellationReceiptIdentityMismatchException;
use App\Exceptions\Billing\CancellationReceiptUnavailableException;
use App\Exceptions\Billing\PacAuthenticationException;
use App\Exceptions\Billing\PacException;
use App\Exceptions\Billing\PacRateLimitException;
use App\Exceptions\Billing\PacValidationException;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Support\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Descarga y almacena el XML/PDF del acuse de una cancelacion ya
 * aceptada. El acuse es adicional a los artifacts cfdi_* originales.
 *
 * No existe una transaccion distribuida DB/filesystem: ambos cuerpos se
 * descargan y validan primero, luego se escriben a temporales y se mueven
 * a su destino, y al final se persiste la metadata en una transaccion
 * corta. Si la DB falla se intenta borrar ambos destinos best-effort.
 */
class DownloadCancellationReceiptService
{
    private const DISK = 'local';

    public function __construct(
        private readonly PacProvider $pacProvider,
        private readonly InvoicePacAuditService $audit,
    ) {}

    public function download(Invoice $invoice): CancellationReceiptResult
    {
        $current = $this->requireCurrentTenantInvoice($invoice);
        $this->assertReceiptCanBeDownloaded($current);
        $this->assertPersistenceSchemaIsReady();

        if ($current->cancellation_receipt_status === 'stored') {
            return $this->returnExistingOrReconcile($current);
        }

        if ($current->cancellation_receipt_status === 'pending') {
            throw new RuntimeException(sprintf(
                'La factura [%d] ya tiene una descarga de acuse en curso.',
                $current->id,
            ));
        }

        $startedAt = microtime(true);
        $reserved = $current;
        $paths = null;
        $filesMoved = false;

        try {
            $reserved = $this->reserve($current);

            $this->audit->appendSafely($reserved, InvoicePacEventType::CancellationReceiptAttempted, [
                'cancellation_receipt_status' => $reserved->cancellation_receipt_status,
            ]);

            $xml = $this->pacProvider->downloadCancellationReceiptXml((string) $reserved->pac_external_id);
            $this->assertValidXml($reserved, $xml);

            $pdf = $this->pacProvider->downloadCancellationReceiptPdf((string) $reserved->pac_external_id);
            $this->assertValidPdf($reserved, $pdf);

            $paths = $this->paths($reserved);
            $this->writeAtomically($paths, $xml, $pdf);
            $filesMoved = true;

            $updated = $this->persistSuccess($reserved, $paths, $xml, $pdf);
            $result = $this->toResult($updated);

            $this->logAttemptSafely(
                $updated,
                $result,
                $startedAt,
                null,
                'stored',
            );
            $this->audit->appendSafely($updated, InvoicePacEventType::CancellationReceiptStored, [
                'xml_size' => $result->xmlSize,
                'pdf_size' => $result->pdfSize,
                'xml_sha256' => $result->xmlHash,
                'pdf_sha256' => $result->pdfHash,
                'downloaded_at' => $result->downloadedAt,
                'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return $result;
        } catch (Throwable $e) {
            if ($filesMoved && is_array($paths)) {
                $this->deleteQuietly($paths['xmlFinal']);
                $this->deleteQuietly($paths['pdfFinal']);
            }

            $this->traceFailure($reserved, $e, $startedAt);
            $this->auditReceiptFailure($reserved, $e, $startedAt);

            throw $e;
        }
    }

    private function returnExistingOrReconcile(Invoice $invoice): CancellationReceiptResult
    {
        $disk = Storage::disk(self::DISK);

        $xmlOk = is_string($invoice->cancellation_receipt_xml_path)
            && $invoice->cancellation_receipt_xml_path !== ''
            && is_string($invoice->cancellation_receipt_xml_sha256)
            && $invoice->cancellation_receipt_xml_sha256 !== ''
            && $disk->exists($invoice->cancellation_receipt_xml_path)
            && hash('sha256', $disk->get($invoice->cancellation_receipt_xml_path)) === $invoice->cancellation_receipt_xml_sha256;

        $pdfOk = is_string($invoice->cancellation_receipt_pdf_path)
            && $invoice->cancellation_receipt_pdf_path !== ''
            && is_string($invoice->cancellation_receipt_pdf_sha256)
            && $invoice->cancellation_receipt_pdf_sha256 !== ''
            && $disk->exists($invoice->cancellation_receipt_pdf_path)
            && hash('sha256', $disk->get($invoice->cancellation_receipt_pdf_path)) === $invoice->cancellation_receipt_pdf_sha256;

        if ($xmlOk && $pdfOk && $invoice->cancellation_receipt_downloaded_at !== null) {
            return $this->toResult($invoice);
        }

        DB::transaction(function () use ($invoice): void {
            $locked = $this->lockedInvoice($invoice);
            $locked->forceFill([
                'cancellation_receipt_status' => 'reconciliation_required',
                'cancellation_receipt_last_error' => 'Archivo local faltante o con hash distinto al registrado; requiere revision manual.',
            ])->save();
        });

        Log::warning('billing.invoice.cancellation_receipt_integrity_failure', [
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
        ]);

        throw new CancellationReceiptArtifactMissingException($invoice->id);
    }

    private function reserve(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice): Invoice {
            $locked = $this->lockedInvoice($invoice);
            $this->assertReceiptCanBeDownloaded($locked);

            if ($locked->cancellation_receipt_status === 'pending') {
                throw new RuntimeException(sprintf(
                    'La factura [%d] ya tiene una descarga de acuse en curso.',
                    $locked->id,
                ));
            }

            if ($locked->cancellation_receipt_status === 'stored') {
                throw new RuntimeException(sprintf(
                    'La factura [%d] ya tiene un acuse almacenado; vuelve a consultar su metadata.',
                    $locked->id,
                ));
            }

            $locked->forceFill(['cancellation_receipt_status' => 'pending'])->save();

            return $locked;
        });
    }

    /**
     * @return array{xmlTmp: string, xmlFinal: string, pdfTmp: string, pdfFinal: string}
     */
    private function paths(Invoice $invoice): array
    {
        $base = sprintf(
            'cancellation-receipts/%d/%d/%s',
            $invoice->company_id,
            $invoice->id,
            $invoice->cfdi_uuid,
        );

        return [
            'xmlFinal' => "{$base}.xml",
            'xmlTmp' => "{$base}.xml.tmp",
            'pdfFinal' => "{$base}.pdf",
            'pdfTmp' => "{$base}.pdf.tmp",
        ];
    }

    /**
     * @param  array{xmlTmp: string, xmlFinal: string, pdfTmp: string, pdfFinal: string}  $paths
     */
    private function writeAtomically(array $paths, string $xml, string $pdf): void
    {
        $disk = Storage::disk(self::DISK);

        if (! $disk->put($paths['xmlTmp'], $xml)) {
            throw new RuntimeException('No se pudo escribir el XML temporal del acuse en el almacenamiento privado.');
        }

        if (! $disk->put($paths['pdfTmp'], $pdf)) {
            $this->deleteQuietly($paths['xmlTmp']);

            throw new RuntimeException('No se pudo escribir el PDF temporal del acuse en el almacenamiento privado.');
        }

        $moved = $disk->move($paths['xmlTmp'], $paths['xmlFinal'])
            && $disk->move($paths['pdfTmp'], $paths['pdfFinal']);

        if (! $moved) {
            foreach ($paths as $path) {
                $this->deleteQuietly($path);
            }

            throw new RuntimeException('No se pudieron mover los archivos temporales del acuse a su ruta final.');
        }
    }

    private function deleteQuietly(string $path): void
    {
        try {
            Storage::disk(self::DISK)->delete($path);
        } catch (Throwable) {
            // Compensacion best-effort: nunca oculta el error original.
        }
    }

    /**
     * @param  array{xmlTmp: string, xmlFinal: string, pdfTmp: string, pdfFinal: string}  $paths
     */
    private function persistSuccess(Invoice $reserved, array $paths, string $xml, string $pdf): Invoice
    {
        return DB::transaction(function () use ($reserved, $paths, $xml, $pdf): Invoice {
            if (app(CurrentTenant::class)->id() !== $reserved->company_id) {
                throw (new ModelNotFoundException)->setModel(Invoice::class, [$reserved->getKey()]);
            }

            $locked = $this->lockedInvoice($reserved);

            if ($locked->pac_external_id !== $reserved->pac_external_id
                || strcasecmp((string) $locked->cfdi_uuid, (string) $reserved->cfdi_uuid) !== 0
                || $locked->pac_status !== 'canceled'
                || $locked->cancellation_status !== 'accepted'
                || $locked->cancellation_receipt_status !== 'pending') {
                throw new RuntimeException(sprintf(
                    'La factura [%d] cambio mientras se descargaba el acuse; no se persiste una respuesta tardia.',
                    $locked->id,
                ));
            }

            $locked->forceFill([
                'cancellation_receipt_xml_path' => $paths['xmlFinal'],
                'cancellation_receipt_pdf_path' => $paths['pdfFinal'],
                'cancellation_receipt_xml_sha256' => hash('sha256', $xml),
                'cancellation_receipt_pdf_sha256' => hash('sha256', $pdf),
                'cancellation_receipt_xml_size' => strlen($xml),
                'cancellation_receipt_pdf_size' => strlen($pdf),
                'cancellation_receipt_downloaded_at' => now(),
                'cancellation_receipt_status' => 'stored',
                'cancellation_receipt_last_error' => null,
            ])->save();

            return $locked;
        });
    }

    private function toResult(Invoice $invoice): CancellationReceiptResult
    {
        return new CancellationReceiptResult(
            xmlPath: (string) $invoice->cancellation_receipt_xml_path,
            pdfPath: (string) $invoice->cancellation_receipt_pdf_path,
            xmlHash: (string) $invoice->cancellation_receipt_xml_sha256,
            pdfHash: (string) $invoice->cancellation_receipt_pdf_sha256,
            xmlSize: (int) $invoice->cancellation_receipt_xml_size,
            pdfSize: (int) $invoice->cancellation_receipt_pdf_size,
            downloadedAt: $invoice->cancellation_receipt_downloaded_at ?? CarbonImmutable::now(),
        );
    }

    /**
     * El estandar SAT del acuse define Acuse/Folios/UUID. Se usa ese
     * camino por nombre local (independiente del namespace), no un XPath
     * inferido de Facturapi. Nunca se reserializan los bytes recibidos.
     */
    private function assertValidXml(Invoice $invoice, string $xml): void
    {
        if (trim($xml) === '' || ! str_starts_with(ltrim($xml), '<')) {
            throw new RuntimeException(sprintf('La factura [%d]: el acuse XML esta vacio o no parece XML.', $invoice->id));
        }

        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw new RuntimeException(sprintf('La factura [%d]: el acuse XML contiene un DOCTYPE no permitido.', $invoice->id));
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;

        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        $errors = libxml_get_errors();
        libxml_clear_errors();

        if (! $loaded || $errors !== [] || $dom->documentElement?->localName !== 'Acuse') {
            throw new RuntimeException(sprintf('La factura [%d]: el acuse XML esta mal formado o no tiene la raiz Acuse esperada.', $invoice->id));
        }

        $nodes = (new DOMXPath($dom))->query(
            '/*[local-name()="Acuse"]/*[local-name()="Folios"]/*[local-name()="UUID"]',
        );

        if ($nodes === false || $nodes->length === 0) {
            throw new RuntimeException(sprintf('La factura [%d]: el acuse XML no contiene Acuse/Folios/UUID.', $invoice->id));
        }

        $matches = false;

        foreach ($nodes as $node) {
            if (strcasecmp(trim((string) $node->textContent), (string) $invoice->cfdi_uuid) === 0) {
                $matches = true;
                break;
            }
        }

        if (! $matches) {
            throw new CancellationReceiptIdentityMismatchException($invoice->id, $nodes->length);
        }
    }

    private function assertValidPdf(Invoice $invoice, string $pdf): void
    {
        if ($pdf === '' || ! str_starts_with($pdf, '%PDF-')) {
            throw new RuntimeException(sprintf('La factura [%d]: el acuse PDF esta vacio o no tiene el encabezado %%PDF-.', $invoice->id));
        }
    }

    private function recordFailure(Invoice $invoice, Throwable $e): string
    {
        $status = $this->failureStatus($e);

        return DB::transaction(function () use ($invoice, $e, $status): string {
            $locked = $this->lockedInvoice($invoice);

            // Una ejecucion concurrente pudo terminar correctamente mientras
            // esta respuesta fallaba. Nunca degradar un acuse ya almacenado.
            if ($locked->cancellation_receipt_status === 'stored') {
                return 'stored';
            }

            $locked->forceFill([
                'cancellation_receipt_status' => $status,
                'cancellation_receipt_last_error' => $this->sanitizeErrorMessage($e),
            ])->save();

            return $status;
        });
    }

    /**
     * Persistencia y logging son diagnostico best-effort: ninguno puede
     * sustituir ni ocultar la excepcion operacional original.
     */
    private function traceFailure(Invoice $invoice, Throwable $error, float $startedAt): void
    {
        $status = $this->failureStatus($error);
        $diagnosticError = null;

        try {
            $status = $this->recordFailure($invoice, $error);
        } catch (Throwable $e) {
            $diagnosticError = $e;
        }

        $this->logAttemptSafely(
            $invoice,
            null,
            $startedAt,
            $error,
            $status,
            $diagnosticError,
        );
    }

    private function failureStatus(Throwable $e): string
    {
        if ($e instanceof CancellationReceiptUnavailableException
            || $e instanceof CancellationReceiptIdentityMismatchException) {
            return 'reconciliation_required';
        }

        return $e instanceof PacValidationException
            || $e instanceof PacAuthenticationException
            || $e instanceof PacRateLimitException
                ? 'failed'
                : 'reconciliation_required';
    }

    private function sanitizeErrorMessage(Throwable $e): string
    {
        if ($e instanceof CancellationReceiptIdentityMismatchException) {
            return $e->getMessage();
        }

        $code = $e instanceof PacException ? ($e->pacCode ?? (string) $e->httpStatus) : null;
        $prefix = $code !== null && $code !== '' ? "[{$code}] " : '';
        $message = $prefix.$e->getMessage();
        $apiKey = (string) config('services.facturapi.test_key');

        if ($apiKey !== '') {
            $message = str_replace($apiKey, '[redacted]', $message);
        }

        return mb_substr(str_ireplace(['Authorization', 'Bearer'], '[redacted]', $message), 0, 500);
    }

    private function logAttempt(
        Invoice $invoice,
        ?CancellationReceiptResult $result,
        float $startedAt,
        ?Throwable $error,
        string $status,
        ?Throwable $diagnosticError = null,
    ): void {
        Log::info('billing.invoice.cancellation_receipt_attempt', [
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'cancellation_receipt_status' => $status,
            'xml_size' => $result?->xmlSize,
            'pdf_size' => $result?->pdfSize,
            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'pac_error_code' => $error instanceof PacException ? $error->pacCode : null,
            'diagnostic_persistence_failed' => $diagnosticError !== null,
        ]);
    }

    private function logAttemptSafely(
        Invoice $invoice,
        ?CancellationReceiptResult $result,
        float $startedAt,
        ?Throwable $error,
        string $status,
        ?Throwable $diagnosticError = null,
    ): void {
        try {
            $this->logAttempt($invoice, $result, $startedAt, $error, $status, $diagnosticError);
        } catch (Throwable) {
            // El fallo del canal de log nunca reemplaza el error original ni
            // revierte una descarga ya persistida correctamente.
        }

        if ($error instanceof CancellationReceiptIdentityMismatchException) {
            $this->logIdentityMismatchSafely($invoice, $error, $startedAt);
        }
    }

    private function logIdentityMismatchSafely(
        Invoice $invoice,
        CancellationReceiptIdentityMismatchException $error,
        float $startedAt,
    ): void {
        try {
            Log::warning('billing.invoice.cancellation_receipt_identity_mismatch', [
                'invoice_id' => $invoice->id,
                'company_id' => $invoice->company_id,
                'pac_provider' => $invoice->pac_provider,
                'pac_external_id' => $this->maskIdentifier((string) $invoice->pac_external_id),
                'expected_uuid' => $this->maskUuid((string) $invoice->cfdi_uuid),
                'receipt_uuid_count' => $error->receiptUuidCount,
                'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        } catch (Throwable) {
            // El fallo del canal de log nunca oculta el mismatch original.
        }
    }

    private function maskIdentifier(string $identifier): string
    {
        return mb_strlen($identifier) <= 12
            ? '[masked]'
            : mb_substr($identifier, 0, 8).'...'.mb_substr($identifier, -4);
    }

    private function maskUuid(string $uuid): string
    {
        return mb_substr($uuid, 0, 8).'...'.mb_substr($uuid, -4);
    }

    private function auditReceiptFailure(Invoice $invoice, Throwable $error, float $startedAt): void
    {
        $fresh = $invoice->fresh() ?? $invoice;
        $context = [
            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'cancellation_receipt_status' => $fresh->cancellation_receipt_status,
        ];

        $type = match (true) {
            $error instanceof CancellationReceiptIdentityMismatchException => InvoicePacEventType::CancellationReceiptIdentityMismatch,
            $error instanceof CancellationReceiptUnavailableException => InvoicePacEventType::CancellationReceiptUnavailable,
            default => InvoicePacEventType::ReconciliationRequired,
        };

        if ($error instanceof CancellationReceiptIdentityMismatchException) {
            $context += [
                'receipt_uuid_count' => $error->receiptUuidCount,
                'expected_uuid_masked' => $this->maskUuid((string) $fresh->cfdi_uuid),
                'pac_external_id_masked' => $this->maskIdentifier((string) $fresh->pac_external_id),
            ];
        }

        $this->audit->appendSafely(
            $fresh,
            $type,
            $context,
            $error instanceof PacException ? ($error->pacCode ?? (string) $error->httpStatus) : null,
        );
    }

    private function assertPersistenceSchemaIsReady(): void
    {
        if (! Schema::hasColumns('invoices', [
            'cancellation_receipt_status',
            'cancellation_receipt_last_error',
            'cancellation_receipt_xml_path',
            'cancellation_receipt_pdf_path',
        ])) {
            throw new RuntimeException(
                'La migracion de artifacts del acuse de cancelacion no esta aplicada. Ejecuta php artisan migrate antes de descargar el acuse.',
            );
        }
    }

    private function assertReceiptCanBeDownloaded(Invoice $invoice): void
    {
        if ($invoice->pac_external_id === null) {
            throw new RuntimeException(sprintf('La factura [%d] no tiene pac_external_id.', $invoice->id));
        }

        if ($invoice->cfdi_uuid === null) {
            throw new RuntimeException(sprintf('La factura [%d] no tiene cfdi_uuid.', $invoice->id));
        }

        if ($invoice->pac_status !== 'canceled') {
            throw new RuntimeException(sprintf('La factura [%d] requiere pac_status=canceled para descargar el acuse.', $invoice->id));
        }

        if ($invoice->cancellation_status !== 'accepted') {
            $detail = in_array($invoice->cancellation_status, ['pending', 'verifying'], true)
                ? 'la cancelacion sigue en curso y el acuse aun no esta disponible'
                : 'cancellation_status debe ser accepted';

            throw new RuntimeException(sprintf('La factura [%d]: %s.', $invoice->id, $detail));
        }
    }

    private function requireCurrentTenantInvoice(Invoice $invoice): Invoice
    {
        $tenantId = app(CurrentTenant::class)->id();

        $fresh = $tenantId !== null
            ? Invoice::withoutGlobalScope(CompanyScope::class)
                ->whereKey($invoice->getKey())
                ->where('company_id', $tenantId)
                ->first()
            : null;

        if ($fresh === null) {
            throw (new ModelNotFoundException)->setModel(Invoice::class, [$invoice->getKey()]);
        }

        return $fresh;
    }

    private function lockedInvoice(Invoice $invoice): Invoice
    {
        return Invoice::withoutGlobalScope(CompanyScope::class)
            ->whereKey($invoice->getKey())
            ->where('company_id', $invoice->company_id)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
