<?php

namespace App\Exceptions\Billing;

/**
 * Mapea un 409 del PAC (Fase 6.2.5) — documentado para el endpoint de
 * timbrado de un borrador. NUNCA se trata como un error de validación
 * genérico: un 409 en `/stamp` es intrínsecamente AMBIGUO (el recurso
 * puede ya estar timbrado, en transición, o en un estado incompatible)
 * — no hay certeza de que la operación no haya surtido efecto del lado
 * del PAC. Por eso StampPacDraftInvoiceService nunca reintenta `/stamp`
 * a ciegas al recibir esta excepción: la clasifica como
 * `reconciliation_required` y exige consultar el estado remoto real
 * antes de decidir cualquier otra cosa.
 */
class PacConflictException extends PacException
{
}
