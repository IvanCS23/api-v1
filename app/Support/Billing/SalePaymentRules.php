<?php

namespace App\Support\Billing;

use App\Support\Sat\SatCatalog;

/**
 * Fase 6.25: forma y método de pago de una venta (CFDI 4.0).
 *
 * Única fuente de las reglas que comparten StoreSaleRequest,
 * UpdateSaleRequest y SaleBillingReadinessService:
 * - payment_form: clave del catálogo c_FormaPago del SAT (Fase 6.26: las 22
 *   formas vigentes del archivo oficial, ver SatCatalog).
 * - payment_method: PUE o PPD.
 * - PPD exige la forma 99 ("Por definir") y PUE no la admite.
 * - Fase 6.28: PPD sigue en el catálogo pero no se puede usar hasta que
 *   exista el complemento de pago (REP); ver isMethodAvailable().
 *
 * La empresa (`default_payment_form` / `default_payment_method`) sólo es
 * respaldo cuando la venta no trae su propio valor (ver effective()).
 */
final class SalePaymentRules
{
    public const PAYMENT_METHODS = ['PUE', 'PPD'];

    public const FORM_TO_BE_DEFINED = '99';

    public const PPD_REQUIRES_FORM_99 = 'SALE_PPD_REQUIRES_FORM_99';

    public const PUE_FORBIDS_FORM_99 = 'SALE_PUE_FORBIDS_FORM_99';

    public const FORM_INVALID = 'SALE_PAYMENT_FORM_INVALID';

    public const PPD_NOT_AVAILABLE = 'SALE_PPD_NOT_AVAILABLE';

    /**
     * Fase 6.28: métodos del catálogo que todavía no se pueden usar. Un CFDI
     * PPD obliga a emitir un complemento de pago por cada cobro, y el sistema
     * aún no lo genera. Es una constante, no configuración: habilitarlo sin
     * el complemento dejaría facturas sin forma de acreditar sus pagos.
     */
    public const UNAVAILABLE_METHODS = ['PPD'];

    public const PPD_NOT_AVAILABLE_MESSAGE = 'El método de pago PPD (parcialidades o diferido) aún no está disponible: requiere el complemento de pago, que se habilitará en una fase posterior. Usa PUE.';

    /** Fase 6.26: la forma existe y está vigente en c_FormaPago. */
    public static function isValidForm(?string $form): bool
    {
        return SatCatalog::isPaymentForm($form);
    }

    /** Fase 6.28: false para un método del catálogo que aún no se admite. */
    public static function isMethodAvailable(?string $method): bool
    {
        return ! in_array($method, self::UNAVAILABLE_METHODS, true);
    }

    /** Código de la incompatibilidad entre forma y método, o null si no hay. */
    public static function violation(?string $form, ?string $method): ?string
    {
        if (blank($form) || blank($method)) {
            return null;
        }

        if ($method === 'PPD' && $form !== self::FORM_TO_BE_DEFINED) {
            return self::PPD_REQUIRES_FORM_99;
        }

        if ($method === 'PUE' && $form === self::FORM_TO_BE_DEFINED) {
            return self::PUE_FORBIDS_FORM_99;
        }

        return null;
    }

    public static function message(string $code): string
    {
        return match ($code) {
            self::PPD_REQUIRES_FORM_99 => 'Con método de pago PPD la forma de pago debe ser 99 (Por definir).',
            self::PUE_FORBIDS_FORM_99 => 'Con método de pago PUE la forma de pago no puede ser 99 (Por definir).',
            self::PPD_NOT_AVAILABLE => self::PPD_NOT_AVAILABLE_MESSAGE,
            default => 'La forma y el método de pago no son compatibles.',
        };
    }

    /**
     * Valor efectivo: el de la venta y, si falta, el de la empresa.
     *
     * @return array{0: ?string, 1: ?string} [forma, método]
     */
    public static function effective(?string $saleForm, ?string $saleMethod, ?string $companyForm, ?string $companyMethod): array
    {
        return [
            filled($saleForm) ? $saleForm : (filled($companyForm) ? $companyForm : null),
            filled($saleMethod) ? $saleMethod : (filled($companyMethod) ? $companyMethod : null),
        ];
    }
}
