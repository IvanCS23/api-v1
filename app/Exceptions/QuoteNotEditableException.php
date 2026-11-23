<?php

namespace App\Exceptions;

use App\Models\Quote;
use RuntimeException;

/**
 * Se lanza cuando QuoteService intenta editar o eliminar una cotización
 * que ya no lo admite (ver Quote::isEditable(): solo Draft y Sent). Los
 * mensajes son los mismos que QuoteController devuelve hoy en su 422, para
 * que el controller pueda traducirla tal cual sin cambiar el contrato.
 */
class QuoteNotEditableException extends RuntimeException
{
    public function __construct(public readonly Quote $quote, string $message)
    {
        parent::__construct($message);
    }

    public static function forUpdate(Quote $quote): self
    {
        return new self($quote, 'Esta cotización ya no se puede editar en su estado actual.');
    }

    public static function forDelete(Quote $quote): self
    {
        return new self($quote, 'Esta cotización ya no se puede eliminar en su estado actual.');
    }
}
