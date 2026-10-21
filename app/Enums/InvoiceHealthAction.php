<?php

namespace App\Enums;

/**
 * Acción sugerida para un hallazgo de salud (Fase 6.18). Es sólo una
 * recomendación para el operador: el diagnóstico nunca la ejecuta.
 */
enum InvoiceHealthAction: string
{
    case Wait = 'wait';
    case Reconcile = 'reconcile';
    case OperationsIssue = 'operations_issue';
    case OperationsCancel = 'operations_cancel';
    case FetchArtifacts = 'fetch_artifacts';
    case FetchReceipt = 'fetch_receipt';
    case ManualReview = 'manual_review';
}
