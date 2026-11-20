<?php

namespace App\Enums;

enum AuditAction: string
{
    case PasswordChanged = 'password_changed';
    case PasswordReset = 'password_reset';
    case SessionsRevoked = 'sessions_revoked';
    case Activated = 'activated';
    case Deactivated = 'deactivated';
    // Fase 6.31: cambio del propio nombre desde la web.
    case ProfileUpdated = 'profile_updated';
    // Fase 6.34: la venta se recalculó al facturar (cálculo estilo SAT);
    // metadata con el total anterior y el nuevo.
    case SaleTotalsRecalculated = 'sale_totals_recalculated';
    // Fase 6.35: se emitió el REP de un pago; metadata con cada documento
    // relacionado y el payment-summary del PAC.
    case PaymentIssued = 'payment_issued';
}
