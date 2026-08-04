<?php

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * Se lanza cuando el PAC responde con `livemode: true` en una operación
 * que este proyecto solo debe ejecutar en TEST (Fase 6.2.4: creación y
 * consulta de borradores). Protege contra una llave/configuración
 * equivocada (ej. una `FACTURAPI_TEST_KEY` mal fijada que en realidad
 * apunta a producción) — nunca se persiste el resultado como si fuera
 * un borrador TEST válido cuando esto ocurre.
 *
 * `remoteId` es el `id` que el PAC asignó al recurso — seguro de
 * registrar (no es información fiscal sensible), útil para localizar
 * manualmente y limpiar el recurso equivocado del lado del PAC.
 */
class PacUnexpectedEnvironmentException extends RuntimeException
{
    public function __construct(public readonly string $context, public readonly string $remoteId)
    {
        parent::__construct(sprintf(
            'El PAC respondió con livemode=true durante "%s" (id remoto: %s); se esperaba exclusivamente entorno TEST (livemode=false). Operación detenida, nada se persistió.',
            $context,
            $remoteId,
        ));
    }
}
