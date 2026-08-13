<?php

namespace App\Services\Billing;

use App\Data\Billing\StoredInvoiceArtifact;
use App\Enums\InvoiceArtifactType;
use App\Exceptions\Billing\InvoiceArtifactIntegrityException;
use App\Exceptions\Billing\InvoiceArtifactUnavailableException;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Sirve únicamente bytes ya almacenados en el disk privado local.
 * No modifica Invoice, no registra evento PAC y no resuelve PacProvider.
 *
 * Los XML/PDF fiscales esperados son pequeños y se leen una sola vez en
 * memoria. Esto permite validar tamaño, SHA-256 y formato sobre exactamente
 * los mismos bytes que posteriormente recibe el cliente, evitando TOCTOU.
 */
class ServeInvoiceArtifactService
{
    private const DISK = 'local';

    public function get(Invoice $invoice, InvoiceArtifactType $type): StoredInvoiceArtifact
    {
        $current = $this->requireCurrentTenantInvoice($invoice);
        $metadata = $this->metadata($current, $type);

        if ($metadata['status'] !== 'stored') {
            throw new InvoiceArtifactUnavailableException($type->isReceipt(), $type->extension());
        }

        if (! is_string($metadata['path']) || $metadata['path'] === ''
            || ! is_string($metadata['hash']) || preg_match('/^[a-f0-9]{64}$/i', $metadata['hash']) !== 1
            || ! is_int($metadata['size']) || $metadata['size'] < 1) {
            throw new InvoiceArtifactIntegrityException;
        }

        $this->assertExpectedPath($current, $type, $metadata['path']);
        $contents = $this->readAndVerify(
            Storage::disk(self::DISK),
            $metadata['path'],
            $metadata['hash'],
            $metadata['size'],
            $type,
        );

        return new StoredInvoiceArtifact(
            contents: $contents,
            contentType: $type->contentType(),
            filename: $this->filename($current, $type),
        );
    }

    /**
     * @return array{status: mixed, path: mixed, hash: mixed, size: mixed}
     */
    private function metadata(Invoice $invoice, InvoiceArtifactType $type): array
    {
        return match ($type) {
            InvoiceArtifactType::CfdiXml => [
                'status' => $invoice->cfdi_artifacts_status,
                'path' => $invoice->cfdi_xml_path,
                'hash' => $invoice->cfdi_xml_sha256,
                'size' => $invoice->cfdi_xml_size,
            ],
            InvoiceArtifactType::CfdiPdf => [
                'status' => $invoice->cfdi_artifacts_status,
                'path' => $invoice->cfdi_pdf_path,
                'hash' => $invoice->cfdi_pdf_sha256,
                'size' => $invoice->cfdi_pdf_size,
            ],
            InvoiceArtifactType::CancellationReceiptXml => [
                'status' => $invoice->cancellation_receipt_status,
                'path' => $invoice->cancellation_receipt_xml_path,
                'hash' => $invoice->cancellation_receipt_xml_sha256,
                'size' => $invoice->cancellation_receipt_xml_size,
            ],
            InvoiceArtifactType::CancellationReceiptPdf => [
                'status' => $invoice->cancellation_receipt_status,
                'path' => $invoice->cancellation_receipt_pdf_path,
                'hash' => $invoice->cancellation_receipt_pdf_sha256,
                'size' => $invoice->cancellation_receipt_pdf_size,
            ],
        };
    }

    private function assertExpectedPath(Invoice $invoice, InvoiceArtifactType $type, string $path): void
    {
        if (str_contains($path, "\0")
            || str_contains($path, '\\')
            || str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:/', $path) === 1
            || in_array('.', explode('/', $path), true)
            || in_array('..', explode('/', $path), true)) {
            throw new InvoiceArtifactIntegrityException;
        }

        $prefix = $type->isReceipt() ? 'cancellation-receipts' : 'cfdi';
        $expected = sprintf(
            '%s/%d/%d/%s.%s',
            $prefix,
            $invoice->company_id,
            $invoice->id,
            $invoice->cfdi_uuid,
            $type->extension(),
        );

        if (! hash_equals($expected, $path)) {
            throw new InvoiceArtifactIntegrityException;
        }
    }

    private function readAndVerify(
        FilesystemAdapter $disk,
        string $path,
        string $expectedHash,
        int $expectedSize,
        InvoiceArtifactType $type,
    ): string {
        try {
            if (! $disk->exists($path) || $disk->size($path) !== $expectedSize) {
                throw new InvoiceArtifactIntegrityException;
            }

            $contents = $disk->get($path);
        } catch (InvoiceArtifactIntegrityException $e) {
            throw $e;
        } catch (Throwable) {
            throw new InvoiceArtifactIntegrityException;
        }

        if (strlen($contents) !== $expectedSize
            || ! hash_equals(strtolower($expectedHash), hash('sha256', $contents))) {
            throw new InvoiceArtifactIntegrityException;
        }

        $validFormat = match ($type) {
            InvoiceArtifactType::CfdiXml,
            InvoiceArtifactType::CancellationReceiptXml => trim($contents) !== ''
                && str_starts_with(ltrim($contents), '<'),
            InvoiceArtifactType::CfdiPdf,
            InvoiceArtifactType::CancellationReceiptPdf => str_starts_with($contents, '%PDF-'),
        };

        if (! $validFormat) {
            throw new InvoiceArtifactIntegrityException;
        }

        return $contents;
    }

    private function filename(Invoice $invoice, InvoiceArtifactType $type): string
    {
        $folio = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $invoice->folio) ?? 'invoice';
        $folio = trim($folio, '._-');
        $folio = $folio !== '' ? $folio : 'invoice-'.$invoice->id;
        $prefix = $type->isReceipt() ? 'ACUSE-' : 'CFDI-';

        return $prefix.$folio.'.'.$type->extension();
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
}
