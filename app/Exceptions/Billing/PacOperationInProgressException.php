<?php

namespace App\Exceptions\Billing;

/**
 * Facturapi rechazó la petición porque otra operación sobre el mismo
 * recurso o la misma llave sigue registrada (Fase 6.20, por `body.code`):
 * `stamping_in_progress`, `invoice_cancellation_in_progress`,
 * `draft_update_in_progress`, `idempotency_key_in_use`.
 *
 * Extiende PacConflictException para heredar su clasificación AMBIGUA:
 * no hay certeza del desenlace de la operación anterior, así que nunca se
 * reintenta a ciegas. Se mapea por código y no por HTTP status, que
 * Facturapi no documenta para estos casos.
 *
 * `idempotency_key_in_use` también llega cuando la petición original ya
 * TERMINÓ (verificado en Facturapi TEST): repetir la llave nunca devuelve
 * el recurso original. Un borrador así se recupera por `external_id`
 * (ReconcileInvoiceWithPacService).
 */
class PacOperationInProgressException extends PacConflictException {}
