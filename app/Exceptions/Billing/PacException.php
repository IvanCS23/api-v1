<?php

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * Base común de todas las excepciones de integración con un PAC.
 *
 * Nunca debe construirse (ni sus subclases) con la API key en el
 * mensaje, en `$pacCode` ni en ningún otro campo — la responsabilidad de
 * no filtrarla es de quien lanza la excepción (ver FacturapiProvider,
 * que solo pasa el cuerpo/código de la respuesta del PAC, jamás las
 * cabeceras de la petición saliente).
 */
abstract class PacException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $pacCode = null,
    ) {
        parent::__construct($message);
    }
}
