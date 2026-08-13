<?php

namespace App\Support\Billing;

use App\Exceptions\Billing\CancellationReceiptArtifactMissingException;
use App\Exceptions\Billing\CancellationReceiptIdentityMismatchException;
use App\Exceptions\Billing\CancellationReceiptUnavailableException;
use App\Exceptions\Billing\CfdiArtifactMismatchException;
use App\Exceptions\Billing\CfdiArtifactMissingException;
use App\Exceptions\Billing\InvoiceNotReadyForPacException;
use App\Exceptions\Billing\PacAmbiguousInvoiceMatchException;
use App\Exceptions\Billing\PacAuthenticationException;
use App\Exceptions\Billing\PacConflictException;
use App\Exceptions\Billing\PacRateLimitException;
use App\Exceptions\Billing\PacReconciliationRequiredException;
use App\Exceptions\Billing\PacResourceCanceledException;
use App\Exceptions\Billing\PacUnavailableException;
use App\Exceptions\Billing\PacUnexpectedEnvironmentException;
use App\Exceptions\Billing\PacUnexpectedResponseException;
use App\Exceptions\Billing\PacValidationException;
use App\Exceptions\InvoiceAlreadyIssuedException;
use App\Exceptions\InvoiceCannotBeCancelledException;
use App\Exceptions\InvoiceCannotBeIssuedException;
use App\Exceptions\InvoiceDraftNotReadyToStampException;
use App\Exceptions\InvoiceIssuanceInProgressException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

/** Traduce únicamente errores esperados a un contrato público estable. */
class InvoicePacActionExceptionResponder
{
    public function respond(Throwable $error): ?JsonResponse
    {
        if ($error instanceof InvoiceNotReadyForPacException) {
            return response()->json([
                'message' => 'La factura no está lista para emisión fiscal.',
                'code' => 'INVOICE_NOT_READY_FOR_PAC',
                'errors' => $error->errors,
            ], 422);
        }

        [$status, $code, $message] = match (true) {
            $error instanceof CancellationReceiptIdentityMismatchException => [
                409,
                CancellationReceiptIdentityMismatchException::ERROR_CODE,
                'El acuse recibido no corresponde al CFDI solicitado.',
            ],
            $error instanceof CancellationReceiptUnavailableException => [
                409,
                'CANCELLATION_RECEIPT_UNAVAILABLE',
                'El acuse de cancelación todavía no está disponible.',
            ],
            $error instanceof CancellationReceiptArtifactMissingException => [
                409,
                'CANCELLATION_RECEIPT_INTEGRITY_FAILURE',
                'El acuse almacenado no supera la validación de integridad.',
            ],
            $error instanceof CfdiArtifactMissingException,
            $error instanceof CfdiArtifactMismatchException => [
                409,
                'CFDI_ARTIFACT_INTEGRITY_FAILURE',
                'Los artifacts CFDI no superan la validación de integridad.',
            ],
            $error instanceof InvoiceCannotBeCancelledException => [
                409,
                'INVOICE_CANNOT_BE_CANCELLED',
                'La factura no puede solicitar cancelación fiscal en su estado actual.',
            ],
            $error instanceof InvoiceCannotBeIssuedException => [
                409,
                'INVOICE_NOT_ELIGIBLE_FOR_PAC',
                'La factura debe estar emitida internamente antes de solicitar el CFDI.',
            ],
            $error instanceof InvoiceAlreadyIssuedException => [
                409,
                'INVOICE_ALREADY_ISSUED',
                'La factura ya cuenta con una emisión fiscal.',
            ],
            $error instanceof InvoiceIssuanceInProgressException => [
                409,
                'PAC_ISSUANCE_IN_PROGRESS',
                'La emisión fiscal ya está en curso.',
            ],
            $error instanceof InvoiceDraftNotReadyToStampException => [
                409,
                'PAC_DRAFT_NOT_READY',
                'El borrador fiscal todavía no está listo para timbrarse.',
            ],
            $error instanceof PacResourceCanceledException => [
                409,
                'PAC_RESOURCE_CANCELED',
                'El recurso fiscal remoto está cancelado y no puede timbrarse.',
            ],
            $error instanceof PacReconciliationRequiredException => [
                409,
                'PAC_RECONCILIATION_REQUIRED',
                'El estado de la emisión fiscal es ambiguo y debe reconciliarse antes de continuar.',
            ],
            $error instanceof PacValidationException => [
                422,
                'PAC_VALIDATION_FAILED',
                'El proveedor fiscal rechazó los datos de la operación.',
            ],
            $error instanceof PacAuthenticationException => [
                503,
                'PAC_AUTHENTICATION_FAILED',
                'El proveedor fiscal no está disponible temporalmente.',
            ],
            $error instanceof PacRateLimitException => [
                429,
                'PAC_RATE_LIMITED',
                'El proveedor fiscal limitó temporalmente las solicitudes.',
            ],
            $error instanceof PacUnavailableException => [
                503,
                'PAC_UNAVAILABLE',
                'El proveedor fiscal no está disponible temporalmente.',
            ],
            $error instanceof ConnectionException => [
                503,
                'PAC_UNAVAILABLE',
                'El proveedor fiscal no está disponible temporalmente.',
            ],
            $error instanceof PacConflictException => [
                409,
                'PAC_CONFLICT',
                'La operación fiscal entra en conflicto con el estado remoto actual.',
            ],
            $error instanceof PacUnexpectedEnvironmentException => [
                502,
                'PAC_ENVIRONMENT_MISMATCH',
                'El proveedor fiscal respondió desde un entorno no permitido.',
            ],
            $error instanceof PacUnexpectedResponseException => [
                502,
                'PAC_UNEXPECTED_RESPONSE',
                'El proveedor fiscal devolvió una respuesta que no puede procesarse de forma segura.',
            ],
            $error instanceof PacAmbiguousInvoiceMatchException => [
                409,
                'PAC_AMBIGUOUS_INVOICE_MATCH',
                'La identidad fiscal remota es ambigua y requiere revisión.',
            ],
            $error::class === RuntimeException::class => [
                409,
                'PAC_ACTION_CONFLICT',
                'La acción fiscal no puede ejecutarse en el estado actual.',
            ],
            default => [null, null, null],
        };

        if ($status === null) {
            return null;
        }

        return response()->json([
            'message' => $message,
            'code' => $code,
        ], $status);
    }
}
