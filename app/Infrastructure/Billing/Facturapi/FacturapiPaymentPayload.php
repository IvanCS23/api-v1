<?php

namespace App\Infrastructure\Billing\Facturapi;

use App\Enums\TaxFactorType;
use App\Models\Payment;
use App\Services\Taxes\DecimalMath;

/**
 * Fase 6.35 (4): payload del REP (CFDI tipo P) para Facturapi y comparación
 * de cada documento relacionado con `payment-summary` (D3).
 *
 * El payload lleva los valores calculados en local (PaymentReceiptPlanner):
 * parcialidad, saldo anterior, monto e impuestos prorrateados. Facturapi
 * calcula el saldo insoluto y los importes con esos datos.
 */
final class FacturapiPaymentPayload
{
    private const TAX_NAMES = ['001' => 'ISR', '002' => 'IVA', '003' => 'IEPS'];

    /**
     * @param  array{customer: array<string, string>, documents: list<array<string, mixed>>}  $plan
     * @return array<string, mixed>
     */
    public static function build(Payment $payment, array $plan, int $folioNumber, string $series, string $key): array
    {
        $payment = array_filter([
            'payment_form' => $payment->payment_form,
            // Hora local de México (como se capturó), sin desplazamiento.
            'date' => $payment->payment_date->format('Y-m-d\TH:i:s'),
            'currency' => 'MXN',
            'numOperacion' => filled($payment->operation_number) ? $payment->operation_number : null,
            'related_documents' => array_map(fn (array $document): array => array_filter([
                'uuid' => $document['uuid'],
                'amount' => (float) $document['amount'],
                'installment' => $document['installment'],
                'last_balance' => (float) $document['previous_balance'],
                'taxability' => $document['taxability'],
                'currency' => 'MXN',
                'series' => $document['series'],
                'folio_number' => $document['folio_number'],
                'taxes' => $document['taxes'] === [] ? null : array_map(self::tax(...), $document['taxes']),
            ], fn ($value) => $value !== null), $plan['documents']),
        ], fn ($value) => $value !== null);

        return [
            'type' => 'P',
            'customer' => [
                'legal_name' => $plan['customer']['legal_name'],
                'tax_id' => $plan['customer']['tax_id'],
                'tax_system' => $plan['customer']['tax_system'],
                'address' => ['zip' => $plan['customer']['zip']],
            ],
            'complements' => [[
                'type' => 'pago',
                'data' => [$payment],
            ]],
            'series' => $series,
            'folio_number' => $folioNumber,
            'external_id' => $key,
            'idempotency_key' => $key,
        ];
    }

    /**
     * Diferencias entre el cálculo local de un documento relacionado y el
     * payment-summary del PAC. Montos comparados a 2 decimales; las bases de
     * los impuestos, también a 2 mientras su redondeo siga por confirmar
     * (ajuste 5); las cifras completas quedan en la auditoría.
     *
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $summary
     * @return list<array{invoice: string, field: string, local: mixed, pac: mixed}>
     */
    public static function compare(array $document, array $summary): array
    {
        $folio = (string) $document['invoice']->folio;
        $differences = [];
        $add = function (string $field, mixed $local, mixed $pac) use (&$differences, $folio): void {
            $differences[] = ['invoice' => $folio, 'field' => $field, 'local' => $local, 'pac' => $pac];
        };

        if (strtolower((string) ($summary['uuid'] ?? '')) !== strtolower($document['uuid'])) {
            $add('uuid', $document['uuid'], $summary['uuid'] ?? null);
        }
        if ((int) ($summary['installment'] ?? 0) !== (int) $document['installment']) {
            $add('installment', $document['installment'], $summary['installment'] ?? null);
        }
        if (self::money($summary['last_balance'] ?? null) !== $document['previous_balance']) {
            $add('last_balance', $document['previous_balance'], $summary['last_balance'] ?? null);
        }
        if (array_key_exists('total', $summary) && self::money($summary['total']) !== $document['total']) {
            $add('total', $document['total'], $summary['total']);
        }
        if (isset($summary['currency']) && $summary['currency'] !== 'MXN') {
            $add('currency', 'MXN', $summary['currency']);
        }

        $local = [];
        foreach ($document['taxes'] as $tax) {
            $local[self::taxKey(self::tax($tax))] = self::money($tax['base']);
        }
        $pac = [];
        foreach (is_array($summary['taxes'] ?? null) ? $summary['taxes'] : [] as $tax) {
            if (is_array($tax)) {
                $pac[self::taxKey($tax)] = self::money($tax['base'] ?? null);
            }
        }
        ksort($local);
        ksort($pac);

        if (array_keys($local) !== array_keys($pac)) {
            $add('taxes', array_keys($local), array_keys($pac));
        } else {
            foreach ($local as $key => $base) {
                if ($pac[$key] !== $base) {
                    $add("taxes.{$key}.base", $base, $pac[$key]);
                }
            }
        }

        return $differences;
    }

    /**
     * Un impuesto de payment_allocation_taxes con la forma de Facturapi.
     *
     * @param  array<string, mixed>  $tax
     * @return array<string, mixed>
     */
    private static function tax(array $tax): array
    {
        return array_merge(
            [
                'base' => (float) $tax['base'],
                'type' => self::TAX_NAMES[$tax['tax']],
                'rate' => $tax['rate'] === null ? 0.0 : (float) $tax['rate'],
            ],
            $tax['factor'] !== TaxFactorType::Tasa->value ? ['factor' => ucfirst($tax['factor'])] : [],
            $tax['kind'] === 'retencion' ? ['withholding' => true] : [],
        );
    }

    /** @param  array<string, mixed>  $tax */
    private static function taxKey(array $tax): string
    {
        return implode('|', [
            strtoupper((string) ($tax['type'] ?? '')),
            ($tax['withholding'] ?? false) ? 'retencion' : 'traslado',
            ucfirst(strtolower((string) ($tax['factor'] ?? 'Tasa'))),
            DecimalMath::round(DecimalMath::of($tax['rate'] ?? 0), 6),
        ]);
    }

    private static function money(mixed $value): ?string
    {
        return $value === null ? null : DecimalMath::round(DecimalMath::of($value), 2);
    }
}
