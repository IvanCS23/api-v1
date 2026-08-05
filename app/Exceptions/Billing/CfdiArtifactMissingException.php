<?php

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * Se lanza cuando la base de datos indica `cfdi_artifacts_status=stored`
 * (con rutas y hashes persistidos) pero el archivo correspondiente ya no
 * existe en Storage — Fase 6.3. Nunca se responde silenciosamente como si
 * el archivo siguiera disponible: la metadata histórica (rutas, hashes,
 * `cfdi_artifacts_downloaded_at`) NUNCA se borra automáticamente al
 * detectar esto (podría ser evidencia de un problema de infraestructura
 * que vale la pena investigar) — ver
 * DownloadInvoiceArtifactsService::artifacts(), que marca
 * `cfdi_artifacts_status=reconciliation_required` en vez de lanzar esta
 * excepción directamente en el flujo normal de "obtener o descargar".
 */
class CfdiArtifactMissingException extends RuntimeException
{
    public function __construct(public readonly int $invoiceId, public readonly string $missingPath)
    {
        parent::__construct(sprintf(
            'La factura [%d] tiene artifacts marcados como almacenados, pero el archivo esperado ya no existe en Storage: %s.',
            $invoiceId,
            $missingPath,
        ));
    }
}
