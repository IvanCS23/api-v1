<?php

namespace App\Exceptions\Billing;

use App\Enums\CfdiCancellationMotive;
use RuntimeException;

/**
 * Fase 6.31: el motivo de cancelación no está disponible en el ambiente de
 * la factura. El 04 exige sustitución y el sistema no emite facturas
 * globales (retirado en ambos ambientes); el 01 exige emitir antes el CFDI
 * sustituto, que el sistema aún no hace (sólo Test). Se rechaza antes de
 * reservar nada.
 */
final class CancellationMotiveUnavailableException extends RuntimeException
{
    /** @param list<string> $available */
    public function __construct(
        public readonly CfdiCancellationMotive $motive,
        public readonly ?string $environment,
        public readonly array $available,
    ) {
        parent::__construct(sprintf(
            'El motivo %s no está disponible en el ambiente %s; disponibles: %s.',
            $motive->value,
            $environment ?? 'desconocido',
            implode(', ', $available),
        ));
    }
}
