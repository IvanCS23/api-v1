<?php

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * Se lanza cuando el `livemode` de una respuesta del PAC no corresponde al
 * ambiente con el que se opera (Fase 6.2.4 lo exigía sólo para Test; Fase
 * 6.31, en ambos sentidos: una llave Test no debe devolver datos Live ni
 * una Live datos Test). Protege contra una llave o configuración
 * equivocada: nunca se persiste el resultado.
 *
 * `remoteId` es el `id` que el PAC asignó al recurso — seguro de
 * registrar (no es información fiscal sensible), útil para localizar
 * manualmente y limpiar el recurso equivocado del lado del PAC.
 */
class PacUnexpectedEnvironmentException extends RuntimeException
{
    public function __construct(
        public readonly string $context,
        public readonly string $remoteId,
        public readonly bool $expectedLivemode = false,
    ) {
        parent::__construct(sprintf(
            'El PAC respondió con livemode=%s durante "%s" (id remoto: %s); se esperaba el ambiente %s (livemode=%s). Operación detenida, nada se persistió.',
            $expectedLivemode ? 'false' : 'true',
            $context,
            $remoteId,
            $expectedLivemode ? 'LIVE' : 'TEST',
            $expectedLivemode ? 'true' : 'false',
        ));
    }
}
