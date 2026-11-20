<?php

namespace App\Services\Payments;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Scopes\CompanyScope;
use App\Services\Taxes\DecimalMath;
use App\Support\Billing\InvoiceFolio;
use App\Support\Billing\PacEnvironment;

/**
 * Fase 6.35 (4): revisa que un pago en borrador se pueda emitir y calcula en
 * local cada documento relacionado del REP (D3):
 *
 * - parcialidad = REP vigentes de la factura + 1 (la numeración tras una
 *   cancelación se define después del paso 8, ajuste 3);
 * - saldo anterior = saldo fiscal (total − REP vigentes);
 * - saldo insoluto = saldo anterior − monto pagado;
 * - impuestos prorrateados (PaymentTaxProrater).
 *
 * No escribe nada. Lanza PaymentNotReadyException con la lista de problemas.
 */
class PaymentReceiptPlanner
{
    public function __construct(
        private readonly InvoiceBalance $balance,
        private readonly PaymentTaxProrater $prorater,
    ) {}

    /**
     * @return array{environment: ?string, customer: array<string, string>, documents: list<array<string, mixed>>}
     */
    public function plan(Payment $payment): array
    {
        $problems = [];

        if (! $payment->isDraft()) {
            throw new PaymentNotDraftException;
        }

        $client = Client::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $payment->company_id)
            ->find($payment->client_id);

        $customer = [
            'legal_name' => (string) $client?->name,
            'tax_id' => strtoupper((string) $client?->rfc),
            'tax_system' => (string) $client?->regimen_fiscal,
            'zip' => (string) $client?->codigo_postal,
        ];
        foreach (['legal_name' => 'nombre o razón social', 'tax_id' => 'RFC', 'tax_system' => 'régimen fiscal', 'zip' => 'código postal'] as $field => $label) {
            if (trim($customer[$field]) === '') {
                $problems[] = "Al cliente le falta el {$label}.";
            }
        }

        $environment = PacEnvironment::forCompany((int) $payment->company_id);
        $allocations = $payment->allocations()->get();
        $receivable = PaymentRegistrar::receivableInvoices()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $payment->company_id)
            ->whereIn('id', $allocations->pluck('invoice_id'))
            ->pluck('id')->all();

        if ($allocations->isEmpty()) {
            $problems[] = 'El pago no está aplicado a ninguna factura.';
        }

        $sum = '0';
        $documents = [];

        foreach ($allocations as $allocation) {
            $invoice = Invoice::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $payment->company_id)
                ->find($allocation->invoice_id);
            $amount = DecimalMath::round(DecimalMath::of($allocation->getRawOriginal('amount')), 2);
            $sum = DecimalMath::add($sum, $amount);

            if ($invoice === null || ! in_array($invoice->id, $receivable, true)) {
                $problems[] = 'La factura '.($invoice?->folio ?? "#{$allocation->invoice_id}").' ya no admite pagos (debe estar timbrada, PPD, en MXN y sin cancelación en curso).';

                continue;
            }

            if ((int) $invoice->client_id !== (int) $payment->client_id) {
                $problems[] = "La factura {$invoice->folio} es de otro cliente.";

                continue;
            }

            if (blank($invoice->pac_external_id)) {
                $problems[] = "La factura {$invoice->folio} no tiene identificador del PAC.";

                continue;
            }

            if ($invoice->pac_environment !== $environment) {
                $problems[] = "La factura {$invoice->folio} se timbró en otro ambiente del PAC.";

                continue;
            }

            $folio = InvoiceFolio::parse($invoice->folio);
            if ($folio === null) {
                $problems[] = "La factura {$invoice->folio} no tiene un folio fiscal válido.";

                continue;
            }

            $previous = $this->balance->fiscal($invoice);
            if (bccomp($amount, $previous, 6) > 0) {
                $problems[] = "El monto aplicado a la factura {$invoice->folio} rebasa su saldo.";

                continue;
            }

            try {
                $taxes = $this->prorater->prorate($invoice, $amount);
            } catch (\RuntimeException|\InvalidArgumentException $e) {
                $problems[] = $e->getMessage();

                continue;
            }

            $documents[] = [
                'allocation' => $allocation,
                'invoice' => $invoice,
                'uuid' => (string) $invoice->cfdi_uuid,
                'series' => $folio->series,
                'folio_number' => $folio->number,
                'currency' => 'MXN',
                'total' => DecimalMath::round(DecimalMath::of($invoice->getRawOriginal('total')), 2),
                'installment' => $this->balance->issuedCount($invoice) + 1,
                'previous_balance' => DecimalMath::round($previous, 2),
                'amount' => $amount,
                'remaining_balance' => DecimalMath::round(DecimalMath::sub($previous, $amount), 2),
                'taxability' => $taxes['taxability'],
                'taxes' => $taxes['taxes'],
            ];
        }

        $total = DecimalMath::round(DecimalMath::of($payment->getRawOriginal('amount')), 2);
        if ($allocations->isNotEmpty() && bccomp($sum, $total, 6) !== 0) {
            $problems[] = 'Lo aplicado a las facturas no suma el monto del pago.';
        }

        if ($problems !== []) {
            throw new PaymentNotReadyException($problems);
        }

        return ['environment' => $environment, 'customer' => $customer, 'documents' => $documents];
    }
}
