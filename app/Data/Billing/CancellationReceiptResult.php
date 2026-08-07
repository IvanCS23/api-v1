<?php

namespace App\Data\Billing;

use Carbon\CarbonImmutable;

/**
 * Metadata del acuse de cancelacion almacenado. Nunca transporta el XML
 * ni el PDF completos y las rutas siempre son relativas al disk privado.
 */
final readonly class CancellationReceiptResult
{
    public function __construct(
        public string $xmlPath,
        public string $pdfPath,
        public string $xmlHash,
        public string $pdfHash,
        public int $xmlSize,
        public int $pdfSize,
        public CarbonImmutable $downloadedAt,
    ) {}
}
