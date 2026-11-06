<?php

namespace App\Support\Billing;

use App\Models\Invoice;
use Illuminate\Http\JsonResponse;

/**
 * Fase 6.31 — Endpoints históricos que no se ofrecen a una factura Live:
 *
 * - POST /invoices/{id}/issue y /cancel: sólo cambian el ERP. En Live
 *   marcarían una factura emitida sin CFDI o cancelarían el ERP con el CFDI
 *   vigente.
 * - POST /invoices/{id}/pac/issue y /pac/cancel: primitivas de bajo nivel
 *   sin las validaciones de la operación empresarial. El panel usa
 *   /operations/issue y /operations/cancel.
 *
 * /pac/reconcile, /pac/artifacts y /pac/cancellation-receipt se conservan:
 * los usa el panel y pasan por el guard y el ambiente fijado.
 *
 * `legacy-invoices` (tabla legacy_invoices, sistema de tickets anterior)
 * no tocaba el PAC; sus rutas se retiraron en la 6.31 por no estar aisladas
 * por empresa (ver routes/api.php).
 */
final class LegacyPacOperations
{
    public const CODE = 'LEGACY_OPERATION_UNAVAILABLE';

    public static function rejectsInLive(Invoice $invoice): ?JsonResponse
    {
        if (PacEnvironment::forInvoice($invoice) !== PacEnvironment::LIVE) {
            return null;
        }

        return response()->json([
            'code' => self::CODE,
            'message' => 'Esta operación histórica no está disponible para facturas en producción. Usa Emitir factura o Cancelar factura.',
        ], 409);
    }
}
