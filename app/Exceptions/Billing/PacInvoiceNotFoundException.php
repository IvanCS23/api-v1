<?php

namespace App\Exceptions\Billing;

/**
 * Facturapi respondió `body.code = invoice_not_found` (Fase 6.20): el
 * recurso no existe para la llave configurada, así que la operación
 * solicitada (timbrar, cancelar, descargar) NO ocurrió. Es un fallo
 * definitivo, nunca ambiguo.
 *
 * Ojo: el mismo código aparece si la llave pertenece a otra organización.
 * Por eso la reconciliación nunca lo interpreta como "el remoto no
 * existe": sólo prueba que la operación no ocurrió con esta llave.
 */
class PacInvoiceNotFoundException extends PacException {}
