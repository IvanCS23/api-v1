<?php

namespace App\Http\Requests\Concerns;

use App\Support\Billing\SalePaymentRules;
use App\Support\Sat\SatCatalog;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Fase 6.25: forma y método de pago de la venta (ver SalePaymentRules).
 * La compatibilidad PPD ↔ 99 se evalúa sobre la combinación final: lo que
 * llega en el payload y, en una edición, lo que la venta ya tenía.
 */
trait ValidatesSalePayment
{
    /** @return array<string, mixed> */
    protected function paymentRules(): array
    {
        return [
            // Fase 6.26: catálogo c_FormaPago completo (antes, cualquier par de
            // dígitos). El regex va primero porque `in` no compara estricto
            // ("003" == "03" en PHP).
            'payment_form' => ['nullable', 'string', 'regex:/^\d{2}$/', Rule::in(array_keys(SatCatalog::paymentForms()))],
            'payment_method' => ['nullable', 'string', Rule::in(SalePaymentRules::PAYMENT_METHODS)],
        ];
    }

    /** @return array<string, string> */
    protected function paymentMessages(): array
    {
        return [
            'payment_form.regex' => 'La forma de pago debe ser una clave de 2 dígitos del catálogo c_FormaPago del SAT.',
            'payment_form.in' => 'La forma de pago no existe en el catálogo c_FormaPago del SAT (por ejemplo 01, 03 o 99).',
            'payment_method.in' => 'El método de pago debe ser PUE o PPD.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['payment_form', 'payment_method'])) {
                return;
            }

            [$currentForm, $currentMethod] = $this->currentPayment();
            $form = $this->exists('payment_form') ? $this->input('payment_form') : $currentForm;
            $method = $this->exists('payment_method') ? $this->input('payment_method') : $currentMethod;

            $violation = SalePaymentRules::violation($form, $method);

            if ($violation !== null) {
                $validator->errors()->add('payment_form', SalePaymentRules::message($violation));
            }
        });
    }

    /**
     * Valores que la venta ya tenía (vacíos al crear).
     *
     * @return array{0: ?string, 1: ?string}
     */
    protected function currentPayment(): array
    {
        return [null, null];
    }
}
