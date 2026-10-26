<?php

namespace App\Support\Billing;

/**
 * Fase 6.25: forma y método de pago de una venta (CFDI 4.0).
 *
 * Única fuente de las reglas que comparten StoreSaleRequest,
 * UpdateSaleRequest y SaleBillingReadinessService:
 * - payment_form: clave de 2 dígitos (c_FormaPago); el catálogo completo
 *   se valida en la Fase 6.26.
 * - payment_method: PUE o PPD.
 * - PPD exige la forma 99 ("Por definir") y PUE no la admite.
 *
 * La empresa (`default_payment_form` / `default_payment_method`) sólo es
 * respaldo cuando la venta no trae su propio valor (ver effective()).
 */
final class SalePaymentRules
{
    public const PAYMENT_METHODS = ['PUE', 'PPD'];

    public const FORM_TO_BE_DEFINED = '99';

    public const FORM_PATTERN = '/^\d{2}$/';

    public const PPD_REQUIRES_FORM_99 = 'SALE_PPD_REQUIRES_FORM_99';

    public const PUE_FORBIDS_FORM_99 = 'SALE_PUE_FORBIDS_FORM_99';

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
