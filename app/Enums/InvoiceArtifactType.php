<?php

namespace App\Enums;

enum InvoiceArtifactType: string
{
    case CfdiXml = 'cfdi_xml';
    case CfdiPdf = 'cfdi_pdf';
    case CancellationReceiptXml = 'cancellation_receipt_xml';
    case CancellationReceiptPdf = 'cancellation_receipt_pdf';

    public function extension(): string
    {
        return match ($this) {
            self::CfdiXml, self::CancellationReceiptXml => 'xml',
            self::CfdiPdf, self::CancellationReceiptPdf => 'pdf',
        };
    }

    public function contentType(): string
    {
        return match ($this) {
            self::CfdiXml, self::CancellationReceiptXml => 'application/xml',
            self::CfdiPdf, self::CancellationReceiptPdf => 'application/pdf',
        };
    }

    public function isReceipt(): bool
    {
        return match ($this) {
            self::CancellationReceiptXml, self::CancellationReceiptPdf => true,
            default => false,
        };
    }
}
