<?php

namespace App\Exceptions\Billing;

/**
 * Facturapi confirmo que el acuse de cancelacion todavia no esta
 * disponible (`invoice_cancellation_receipt_unavailable`). Se mantiene
 * separado de un error de validacion generico para permitir reconciliar.
 */
class CancellationReceiptUnavailableException extends PacException {}
