<?php

namespace App\Enums;

/**
 * Estados del ciclo de vida de una cotización.
 *
 * Reglas de negocio (aplicadas en QuoteController, no en el modelo):
 * - Draft y Sent son editables (datos y líneas).
 * - Approved es de solo lectura salvo por una acción: convertirse en
 *   venta (QuoteToSaleConverter). No es alcanzable directamente vía
 *   actualización genérica de status — solo Draft/Sent/Rejected/Expired
 *   lo son; Converted solo se alcanza a través de la conversión.
 * - Rejected, Expired y Converted son de solo lectura.
 */
enum QuoteStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Converted = 'converted';
}
