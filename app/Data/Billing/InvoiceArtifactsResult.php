<?php

namespace App\Data\Billing;

use Carbon\CarbonImmutable;

/**
 * Resultado normalizado de una descarga (o recuperación idempotente) de
 * los archivos fiscales de un CFDI ya timbrado — Fase 6.3. Deliberadamente
 * NUNCA transporta el contenido binario completo del XML/PDF: solo rutas
 * (relativas al disk de Storage, nunca absolutas del servidor) y
 * metadatos de integridad. Quien necesite los bytes reales debe leerlos
 * desde Storage usando `xmlPath`/`pdfPath` — este DTO es el resultado que
 * cruza límites de servicio/comando/log, donde nunca debe viajar el
 * contenido fiscal completo.
 */
final class InvoiceArtifactsResult
{
    public function __construct(
        public readonly string $xmlPath,
        public readonly string $pdfPath,
        public readonly string $xmlHash,
        public readonly string $pdfHash,
        public readonly int $xmlSize,
        public readonly int $pdfSize,
        public readonly CarbonImmutable $downloadedAt,
    ) {}
}
