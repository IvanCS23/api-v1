<?php

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * Se lanza cuando el UUID fiscal (`TimbreFiscalDigital/@UUID`) leído del
 * XML descargado del PAC NO coincide (comparación insensible a
 * mayúsculas/minúsculas) con `Invoice::cfdi_uuid` ya persistido
 * localmente — Fase 6.3. Nunca se confía ciegamente en el archivo
 * remoto: si el PAC devolviera, por error o por una respuesta cacheada
 * incorrecta, el XML de OTRA factura, esta discrepancia lo detecta antes
 * de marcar los artifacts como `stored` y ANTES de sobrescribir cualquier
 * dato local. `Invoice::cfdi_uuid` nunca se reemplaza automáticamente a
 * partir del XML — es la Invoice local la fuente de verdad para decidir
 * si el archivo pertenece a esta factura, no al revés.
 */
class CfdiArtifactMismatchException extends RuntimeException
{
    public function __construct(public readonly int $invoiceId, public readonly string $expectedUuid, public readonly string $foundUuid)
    {
        parent::__construct(sprintf(
            'El UUID del XML descargado (%s) no coincide con Invoice::cfdi_uuid (%s) para la factura [%d]; no se persisten los artifacts.',
            $foundUuid,
            $expectedUuid,
            $invoiceId,
        ));
    }
}
