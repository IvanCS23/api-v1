<?php

namespace App\Exceptions\Billing;

/**
 * La configuración del PAC (modo/llave) no permite operar. Se lanza antes
 * de construir cualquier petición HTTP, así que el PAC nunca recibió nada:
 * es un fallo definitivo, por eso extiende PacAuthenticationException y
 * los servicios lo clasifican como `failed`, nunca como ambiguo.
 *
 * El mensaje nunca incluye la llave ni fragmentos de ella.
 */
class PacConfigurationException extends PacAuthenticationException
{
    public function __construct(string $reason)
    {
        parent::__construct(
            "La configuración del PAC no permite operar ({$reason}); no se contactó al proveedor.",
            null,
            $reason,
        );
    }
}
