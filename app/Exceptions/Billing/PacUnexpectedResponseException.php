<?php

namespace App\Exceptions\Billing;

/**
 * Se lanza cuando la respuesta del PAC no puede interpretarse de forma
 * segura: JSON inválido, ausencia de los campos mínimos esperados, o un
 * código de estado HTTP fuera de todo rango mapeado explícitamente.
 */
class PacUnexpectedResponseException extends PacException
{
}
