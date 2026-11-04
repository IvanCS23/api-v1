<?php

namespace App\Exceptions\Billing;

use RuntimeException;
use Throwable;

/**
 * Fase 6.30: el envío de una factura por correo no se hizo (o no se sabe si
 * se hizo). Lleva el código y el estado HTTP públicos; el mensaje nunca
 * contiene direcciones de correo ni respuestas del PAC.
 */
final class InvoiceEmailException extends RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notAllowed(): self
    {
        return new self('INVOICE_EMAIL_NOT_ALLOWED', 409, 'Sólo se puede enviar por correo una factura con CFDI vigente.');
    }

    /** El CFDI está cancelado: Facturapi lo enviaría sin ninguna marca de cancelación. */
    public static function canceled(): self
    {
        return new self('INVOICE_EMAIL_CFDI_CANCELED', 409, 'El CFDI está cancelado y no se envía por correo: Facturapi lo mandaría sin ninguna marca de cancelación y el correo diría que se emitió un comprobante. Avisa al cliente de la cancelación por otro medio.');
    }

    public static function noRecipients(): self
    {
        return new self('INVOICE_EMAIL_NO_RECIPIENTS', 422, 'El cliente no tiene un correo válido. Agrega uno en Clientes o captura los destinatarios.');
    }

    public static function invalidRecipients(int $max): self
    {
        return new self('INVOICE_EMAIL_RECIPIENTS_INVALID', 422, "Revisa los destinatarios: deben ser correos válidos y como máximo {$max}.");
    }

    public static function recipientsLocked(): self
    {
        return new self('INVOICE_EMAIL_RECIPIENTS_LOCKED', 403, 'Tu rol sólo puede enviar la factura a los correos registrados del cliente.');
    }

    public static function dailyLimit(int $limit): self
    {
        return new self('INVOICE_EMAIL_DAILY_LIMIT', 429, "La empresa alcanzó el límite de {$limit} envíos por correo en 24 horas.");
    }

    public static function uncertain(Throwable $previous): self
    {
        return new self('INVOICE_EMAIL_UNCERTAIN', 502, 'No se pudo confirmar si el proveedor fiscal envió el correo. Revisa con el cliente antes de reenviar.', $previous);
    }
}
