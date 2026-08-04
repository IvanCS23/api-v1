<?php

namespace App\Services\Billing;

use App\Models\Company;

/**
 * Evalúa si una Company tiene la configuración fiscal mínima para que
 * una Sale suya pueda, más adelante, producir una Invoice timbrable
 * (Fase 6.2.3). Puramente de lectura: no corrige nada, no llama a
 * ningún PAC, no modifica Company ni ninguna Sale/Invoice existente.
 *
 * Distingue tres cosas que un valor `null` podría significar (a
 * propósito, para no confundirlas — ver auditoría de la Fase 6.2.3):
 * - dato configurado internamente (valor presente y válido);
 * - dato omitido deliberadamente (`default_payment_method` es nullable
 *   por diseño — significa "usa el default de Facturapi", no "falta");
 * - default aplicado por el proveedor (Facturapi documenta "PUE" como
 *   su propio default si se omite `payment_method` — este servicio
 *   nunca asume ni escribe ese valor, solo permite que `null` siga
 *   siendo `null`).
 *
 * `default_payment_form` SÍ es requerido (Facturapi lo exige siempre,
 * sin default posible — ver auditoría). `default_payment_method` es
 * nullable por decisión de arquitectura explícita: si tiene valor, debe
 * ser exactamente "PUE" o "PPD" (SAT c_MetodoPago); si es null, es una
 * omisión deliberada, no un error.
 */
class CompanyBillingReadinessService
{
    private const VALID_PAYMENT_METHODS = ['PUE', 'PPD'];

    /**
     * @return array{ready: bool, errors: array<int, array{code: string, field: string, message: string}>}
     */
    public function evaluate(Company $company): array
    {
        $errors = [];

        if (blank($company->default_payment_form)) {
            $errors[] = $this->error(
                'COMPANY_PAYMENT_FORM_MISSING',
                'default_payment_form',
                'La empresa no tiene configurada una forma de pago SAT por defecto (default_payment_form), requerida para timbrar.',
            );
        } elseif (! preg_match('/^\d{2}$/', (string) $company->default_payment_form)) {
            $errors[] = $this->error(
                'COMPANY_PAYMENT_FORM_INVALID_FORMAT',
                'default_payment_form',
                'default_payment_form debe ser exactamente 2 dígitos numéricos del catálogo SAT c_FormaPago.',
            );
        }

        if ($company->default_payment_method !== null
            && ! in_array($company->default_payment_method, self::VALID_PAYMENT_METHODS, true)) {
            $errors[] = $this->error(
                'COMPANY_PAYMENT_METHOD_INVALID',
                'default_payment_method',
                'default_payment_method, cuando tiene valor, debe ser exactamente "PUE" o "PPD" (SAT c_MetodoPago).',
            );
        }

        return [
            'ready' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * @return array{code: string, field: string, message: string}
     */
    private function error(string $code, string $field, string $message): array
    {
        return ['code' => $code, 'field' => $field, 'message' => $message];
    }
}
