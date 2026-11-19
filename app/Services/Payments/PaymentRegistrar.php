<?php

namespace App\Services\Payments;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Taxes\DecimalMath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fase 6.35 (2): alta y edición de un pago en borrador y su reparto.
 *
 * Todo ocurre en una transacción con las facturas bloqueadas
 * (`lockForUpdate`), para que dos pagos simultáneos no rebasen el saldo.
 * Reglas (D1, D2):
 * - cada factura es de la empresa, del mismo cliente, está timbrada, es PPD,
 *   en MXN, y no tiene una cancelación pendiente ni aceptada;
 * - lo aplicado a cada factura no rebasa su saldo disponible (saldo fiscal
 *   menos lo reservado por otros borradores);
 * - la suma de lo aplicado es igual al monto del pago.
 *
 * Parcialidad, saldo anterior y saldo insoluto no se fijan aquí: se fijan al
 * emitir el REP, contra el payment-summary del PAC (D3).
 */
class PaymentRegistrar
{
    /** Cancelaciones que impiden registrar pagos a la factura. */
    private const BLOCKING_CANCELLATIONS = ['pending', 'accepted'];

    public function __construct(
        private readonly InvoiceBalance $balance,
        private readonly PaymentNumberGenerator $numbers,
    ) {}

    /**
     * Facturas que admiten pagos (timbradas, PPD, MXN, sin cancelación en
     * curso ni aceptada). Sin filtrar por saldo.
     *
     * @return Builder<Invoice>
     */
    public static function receivableInvoices(): Builder
    {
        return Invoice::query()
            ->where('status', InvoiceStatus::Issued->value)
            ->whereNotNull('cfdi_uuid')
            ->where('payment_method', 'PPD')
            ->where(fn ($query) => $query->whereNull('currency')->orWhere('currency', 'MXN'))
            ->where(fn ($query) => $query->whereNull('cancellation_status')
                ->orWhereNotIn('cancellation_status', self::BLOCKING_CANCELLATIONS));
    }

    /** @param  array<string, mixed>  $data  validado por StorePaymentRequest */
    public function create(array $data, User $user, int $companyId): Payment
    {
        return DB::transaction(function () use ($data, $user, $companyId): Payment {
            $payment = new Payment($this->attributes($data));
            $payment->company_id = $companyId;
            $payment->created_by = $user->id;
            $payment->folio = $this->numbers->next($companyId);
            $payment->status = PaymentStatus::Draft;

            $invoices = $this->lockAndCheck($data, null);
            $payment->save();
            $this->writeAllocations($payment, $data['allocations'], $invoices);

            return $payment;
        });
    }

    /** @param  array<string, mixed>  $data  validado por UpdatePaymentRequest */
    public function update(Payment $payment, array $data): Payment
    {
        return DB::transaction(function () use ($payment, $data): Payment {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if (! $locked->isDraft()) {
                throw new PaymentNotDraftException;
            }

            $invoices = $this->lockAndCheck($data, $locked->id);
            $locked->fill($this->attributes($data))->save();
            $locked->allocations()->delete();
            $this->writeAllocations($locked, $data['allocations'], $invoices);

            return $locked;
        });
    }

    public function delete(Payment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if (! $locked->isDraft()) {
                throw new PaymentNotDraftException;
            }

            // Libera lo reservado: las asignaciones de un borrador eliminado
            // no deben seguir apartando saldo.
            $locked->allocations()->delete();
            $locked->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'client_id' => $data['client_id'],
            'payment_date' => $data['payment_date'],
            'payment_form' => $data['payment_form'],
            'currency' => 'MXN',
            'exchange_rate' => 1,
            'amount' => DecimalMath::round(DecimalMath::of($data['amount']), 2),
            'operation_number' => $data['operation_number'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, Invoice> facturas bloqueadas, por id
     */
    private function lockAndCheck(array $data, ?int $paymentId): array
    {
        $allocations = array_values($data['allocations']);
        $ids = array_map(fn (array $allocation): int => (int) $allocation['invoice_id'], $allocations);

        // Bloqueo en orden de id para evitar interbloqueos entre pagos.
        $invoices = Invoice::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $receivable = self::receivableInvoices()->whereIn('id', $ids)->pluck('id')->all();

        $errors = [];
        $sum = '0';

        foreach ($allocations as $index => $allocation) {
            $field = "allocations.{$index}";
            $invoice = $invoices->get((int) $allocation['invoice_id']);
            $amount = DecimalMath::round(DecimalMath::of($allocation['amount']), 2);
            $sum = DecimalMath::add($sum, $amount);

            if ($invoice === null) {
                $errors["{$field}.invoice_id"] = 'La factura no existe.';

                continue;
            }

            if ((int) $invoice->client_id !== (int) $data['client_id']) {
                $errors["{$field}.invoice_id"] = "La factura {$invoice->folio} es de otro cliente.";

                continue;
            }

            if (! in_array($invoice->id, $receivable, true)) {
                $errors["{$field}.invoice_id"] = "La factura {$invoice->folio} no admite pagos: debe estar timbrada, con método PPD, en MXN y sin cancelación en curso.";

                continue;
            }

            $available = $this->balance->available($invoice, $paymentId);
            if (bccomp($amount, $available, 6) > 0) {
                $errors["{$field}.amount"] = "El monto rebasa el saldo disponible de la factura {$invoice->folio} ({$this->money($available)}).";
            }
        }

        $total = DecimalMath::round(DecimalMath::of($data['amount']), 2);
        if ($errors === [] && bccomp($sum, $total, 6) !== 0) {
            $errors['allocations'] = "Lo aplicado a las facturas ({$this->money($sum)}) debe sumar el monto del pago ({$this->money($total)}).";
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $invoices->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $allocations
     * @param  array<int, Invoice>  $invoices
     */
    private function writeAllocations(Payment $payment, array $allocations, array $invoices): void
    {
        foreach (array_values($allocations) as $allocation) {
            $invoice = $invoices[(int) $allocation['invoice_id']];
            $model = $payment->allocations()->make([
                'invoice_id' => $invoice->id,
                'amount' => DecimalMath::round(DecimalMath::of($allocation['amount']), 2),
            ]);
            $model->company_id = $payment->company_id;
            $model->save();
        }
    }

    private function money(string $value): string
    {
        return '$'.number_format((float) $value, 2);
    }
}
