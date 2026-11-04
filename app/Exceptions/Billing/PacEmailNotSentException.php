<?php

namespace App\Exceptions\Billing;

/**
 * Fase 6.30: Facturapi respondió 200 sin `ok: true` al enviar el CFDI por
 * correo. Es un rechazo definitivo (el PAC contestó que no lo envió), no un
 * resultado incierto.
 */
class PacEmailNotSentException extends PacException
{
    public function __construct()
    {
        parent::__construct('El proveedor fiscal respondió que no envió el correo.', 200, 'email_not_sent');
    }
}
