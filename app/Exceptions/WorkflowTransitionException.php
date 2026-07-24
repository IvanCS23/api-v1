<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Se lanza cuando SaleWorkflow/QuoteWorkflow rechaza una transición: el
 * estado actual del documento no permite la acción solicitada (transición
 * inválida), o el documento no cumple una precondición de negocio (por
 * ejemplo, confirmar una venta sin líneas). El controller la traduce
 * siempre a una respuesta 422 con el mensaje tal cual.
 */
class WorkflowTransitionException extends RuntimeException
{
}
