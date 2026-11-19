<?php

namespace App\Http\Requests;

use App\Support\Billing\SalePaymentRules;
use App\Support\Sat\SatCatalog;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Fase 6.35: alta de un pago recibido y su reparto entre facturas PPD.
 *
 * Aquí se valida la forma del payload. Lo que depende del estado de las
 * facturas (timbradas, PPD, mismo cliente, saldo disponible, suma igual al
 * monto) lo revisa PaymentRegistrar dentro de la transacción, con las
 * facturas bloqueadas.
 *
 * folio, status, created_by y los datos del PAC nunca se leen del payload.
 */
class StorePaymentRequest extends FormRequest
{
    /** Importe en MXN: hasta 2 decimales (D1: sólo MXN). */
    private const AMOUNT_REGEX = '/^\d{1,12}(\.\d{1,2})?$/';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = app(CurrentTenant::class)->id();

        return [
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'payment_date' => ['required', 'date', 'before_or_equal:now'],
            // En el REP la forma de pago es la real; 99 (por definir) no aplica.
            'payment_form' => ['required', 'string', 'regex:/^\d{2}$/', Rule::in(array_keys(SatCatalog::paymentForms())), Rule::notIn([SalePaymentRules::FORM_TO_BE_DEFINED])],
            'currency' => ['nullable', 'string', Rule::in(['MXN'])],
            'amount' => ['required', 'regex:'.self::AMOUNT_REGEX, 'not_regex:/^0+(\.0+)?$/'],
            'operation_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'allocations' => ['required', 'array', 'min:1', 'max:50'],
            'allocations.*.invoice_id' => ['required', 'integer', 'distinct'],
            'allocations.*.amount' => ['required', 'regex:'.self::AMOUNT_REGEX, 'not_regex:/^0+(\.0+)?$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'payment_date.before_or_equal' => 'La fecha del pago no puede ser futura.',
            'payment_form.regex' => 'La forma de pago debe ser una clave de 2 dígitos del catálogo c_FormaPago del SAT.',
            'payment_form.in' => 'La forma de pago no existe en el catálogo c_FormaPago del SAT.',
            'payment_form.not_in' => 'En un complemento de pago la forma de pago es la real; 99 (Por definir) no aplica.',
            'currency.in' => 'Por ahora sólo se registran pagos en MXN.',
            'amount.regex' => 'El monto debe ser un importe con hasta 2 decimales.',
            'amount.not_regex' => 'El monto debe ser mayor que cero.',
            'allocations.required' => 'Indica al menos una factura a la que se aplica el pago.',
            'allocations.*.invoice_id.distinct' => 'Cada factura sólo puede aparecer una vez en el pago.',
            'allocations.*.amount.regex' => 'El monto aplicado debe ser un importe con hasta 2 decimales.',
            'allocations.*.amount.not_regex' => 'El monto aplicado debe ser mayor que cero.',
        ];
    }
}
