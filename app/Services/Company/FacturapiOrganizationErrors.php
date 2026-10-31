<?php

namespace App\Services\Company;

use App\Exceptions\Billing\PacAuthenticationException;
use App\Exceptions\Billing\PacConfigurationException;
use App\Exceptions\Billing\PacException;
use App\Exceptions\Billing\PacValidationException;
use App\Exceptions\Company\CompanyFiscalException;
use App\Support\Billing\PacGuardRejectionLog;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fase 6.27b — Traduce un fallo de FacturapiOrganizationClient a una
 * respuesta propia y lo registra sin secretos (empresa, operación, código
 * y estado HTTP), para que un operador lo diagnostique sin reproducirlo.
 */
final class FacturapiOrganizationErrors
{
    public static function translate(Throwable $error, int $companyId, string $operation, string $rejectedCode, string $rejectedPrefix): CompanyFiscalException
    {
        $pacCode = $error instanceof PacException ? $error->pacCode : null;
        $httpStatus = $error instanceof PacException ? $error->httpStatus : null;

        $translated = match (true) {
            $error instanceof PacConfigurationException => new CompanyFiscalException('PAC_CONFIGURATION_INVALID', 'La conexión con el proveedor fiscal no está configurada para operar. No se guardó nada.', 503),
            $error instanceof PacAuthenticationException => new CompanyFiscalException('PAC_AUTHENTICATION_FAILED', 'Facturapi rechazó la credencial de la cuenta. No se guardó nada.', 502),
            $error instanceof PacValidationException => new CompanyFiscalException($rejectedCode, $rejectedPrefix.' '.$error->getMessage(), 422),
            default => new CompanyFiscalException('PAC_UNAVAILABLE', 'No se pudo completar la operación con Facturapi. No se guardó nada; intenta de nuevo.', 503),
        };

        PacGuardRejectionLog::record($error, [
            'company_id' => $companyId,
            'action' => $operation,
            'user_id' => auth('api')->id(),
        ]);

        Log::warning("billing.company.{$operation}_failed", [
            'company_id' => $companyId,
            'code' => $translated->errorCode,
            'pac_code' => $pacCode,
            'http_status' => $httpStatus,
        ]);

        return $translated;
    }
}
