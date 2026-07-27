<?php

namespace App\Enums;

/**
 * Estados del dominio interno de Invoice, previos/paralelos a cualquier
 * timbrado real (Facturapi no está integrado todavía). `Issued` es la
 * única transición irreversible modelada aquí: una vez Issued, la
 * Invoice es completamente inmutable (ver InvoiceWorkflow, que no expone
 * ningún método para salir de ese estado). Las reglas de transición
 * viven en InvoiceWorkflow, no en el modelo.
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Issued = 'issued';
    case Cancelled = 'cancelled';
}
