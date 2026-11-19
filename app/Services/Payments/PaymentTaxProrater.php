<?php

namespace App\Services\Payments;

use App\Enums\TaxFactorType;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceItemTax;
use App\Models\PaymentAllocation;
use App\Models\PaymentAllocationTax;
use App\Models\Scopes\CompanyScope;
use App\Services\Taxes\DecimalMath;
use App\Services\Taxes\LineTaxWriter;

/**
 * Fase 6.35 (3): impuestos del documento relacionado (ImpuestosDR) de un
 * pago, prorrateados al monto pagado, con aritmética exacta.
 *
 * Por cada grupo de impuesto de la factura (impuesto, traslado/retención,
 * factor, tasa), desde invoice_item_taxes:
 * - proporción = monto pagado / total de la factura;
 * - BaseDR = suma de las bases del grupo × proporción;
 * - ImporteDR: tasa → BaseDR × tasa; cuota → importe del grupo × proporción;
 *   exento → sin importe (sólo base).
 * - ObjetoImpDR: 02 si la factura tiene impuestos, 01 si no.
 *
 * Redondeo de BaseDR e ImporteDR: POR CONFIRMAR (ajuste 5) hasta pasar la
 * guía de llenado del complemento de pagos 2.0. Mientras tanto se guardan
 * con 6 decimales (los que admite el Anexo 20) y `amount_rounded` a 2; el
 * paso 8 los compara contra el payment-summary de Facturapi.
 */
class PaymentTaxProrater
{
    public const ROUNDING_STATUS = 'por_confirmar';

    public function __construct(private readonly LineTaxWriter $taxWriter = new LineTaxWriter) {}

    /**
     * @return array{taxability: string, taxes: list<array<string, mixed>>}
     */
    public function prorate(Invoice $invoice, string $paid): array
    {
        $total = DecimalMath::of($invoice->getAttributes()['total'] ?? '0');
        if (bccomp($total, '0', 6) <= 0) {
            throw new \InvalidArgumentException("La factura {$invoice->folio} no tiene un total positivo.");
        }

        $proportion = bcdiv(DecimalMath::of($paid), $total, DecimalMath::SCALE);
        $taxes = [];

        foreach ($this->groups($invoice) as $group) {
            $base = DecimalMath::round(DecimalMath::mul($group['base'], $proportion), 6);
            $amount = match ($group['factor']) {
                TaxFactorType::Exento->value => null,
                TaxFactorType::Tasa->value => DecimalMath::round(DecimalMath::mul($base, DecimalMath::of($group['rate'])), 6),
                default => DecimalMath::round(DecimalMath::mul($group['amount'], $proportion), 6),
            };

            $taxes[] = [
                'tax' => $group['tax'],
                'kind' => $group['kind'],
                'factor' => $group['factor'],
                'rate' => $group['rate'],
                'ieps_mode' => $group['ieps_mode'],
                'position' => count($taxes),
                'base' => $base,
                'amount' => $amount,
                'amount_rounded' => $amount === null ? '0.00' : DecimalMath::round($amount, 2),
            ];
        }

        return ['taxability' => $taxes === [] ? '01' : '02', 'taxes' => $taxes];
    }

    /** Reemplaza los impuestos guardados de la asignación. */
    public function store(PaymentAllocation $allocation, array $taxes): void
    {
        PaymentAllocationTax::withoutGlobalScope(CompanyScope::class)
            ->where('payment_allocation_id', $allocation->id)->delete();

        foreach ($taxes as $row) {
            $tax = new PaymentAllocationTax([...$row, 'payment_allocation_id' => $allocation->id]);
            $tax->company_id = $allocation->company_id;
            $tax->save();
        }

        $allocation->unsetRelation('taxes');
    }

    /**
     * Grupos de impuesto de la factura, en el orden en que aparecen.
     *
     * @return list<array{tax: string, kind: string, factor: string, rate: ?string, ieps_mode: ?string, base: string, amount: string}>
     */
    private function groups(Invoice $invoice): array
    {
        $items = InvoiceItem::withoutGlobalScope(CompanyScope::class)
            ->where('invoice_id', $invoice->id)
            ->orderBy('id')->get();

        $groups = [];
        foreach ($items as $item) {
            $rows = $this->taxWriter->rowsToCopy($item, InvoiceItemTax::class, 'invoice_item_id');

            // Una línea con impuestos guardados pero sin filas ni tasa no se
            // puede prorratear: mejor detenerse que mandar un REP sin ellos.
            if ($rows === [] && bccomp(DecimalMath::of($item->getRawOriginal('tax_total')), '0', 6) !== 0) {
                throw new \RuntimeException("La factura {$invoice->folio} tiene una línea con impuestos sin desglose; no se pueden prorratear.");
            }

            foreach ($rows as $row) {
                $kind = $row['kind'] instanceof \BackedEnum ? $row['kind']->value : (string) $row['kind'];
                $factor = $row['factor'] instanceof \BackedEnum ? $row['factor']->value : (string) $row['factor'];
                $rate = $row['rate'] === null ? null : DecimalMath::round(DecimalMath::of($row['rate']), 6);
                $key = implode('|', [$row['tax'], $kind, $factor, $rate ?? '']);

                $groups[$key] ??= [
                    'tax' => (string) $row['tax'],
                    'kind' => $kind,
                    'factor' => $factor,
                    'rate' => $rate,
                    'ieps_mode' => $row['ieps_mode'] ?? null,
                    'base' => '0',
                    'amount' => '0',
                ];
                $groups[$key]['base'] = DecimalMath::add($groups[$key]['base'], DecimalMath::of($row['base'] ?? '0'));
                $groups[$key]['amount'] = DecimalMath::add($groups[$key]['amount'], DecimalMath::of($row['amount'] ?? $row['amount_rounded'] ?? '0'));
            }
        }

        return array_values($groups);
    }
}
