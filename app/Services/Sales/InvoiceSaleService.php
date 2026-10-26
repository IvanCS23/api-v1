<?php

namespace App\Services\Sales;

use App\Enums\InvoiceStatus;
use App\Enums\SaleStatus;
use App\Exceptions\SaleCancelledException;
use App\Exceptions\SaleNotBillableException;
use App\Models\Invoice;
use App\Models\Sale;
use App\Models\Scopes\CompanyScope;
use App\Services\Billing\InvoiceWorkflow;
use App\Services\Billing\SaleBillingReadinessService;
use App\Services\Billing\SaleToInvoiceConverter;
use Illuminate\Support\Facades\DB;

/**
 * Fase 6.25 — POST /api/sales/{sale}/invoice: de una venta a una factura
 * lista para emitir, en una sola operación atómica e idempotente.
 *
 * Dentro de una transacción, con la venta bloqueada:
 * 1. Si ya tiene factura vigente la devuelve sin crear otra (una factura en
 *    borrador del flujo anterior se marca ready; ready, issued o cancelled
 *    se devuelven sin tocar nada).
 * 2. Una venta cancelada no se factura (SaleCancelledException).
 * 3. Readiness previo a facturar (SaleNotBillableException con los
 *    problemas concretos; no cambia nada).
 * 4. Conserva los estados de la venta: draft → pending → confirmed
 *    (SaleWorkflow), convierte (SaleToInvoiceConverter) y deja la factura
 *    ready (InvoiceWorkflow).
 *
 * Cualquier fallo revierte todo: los servicios usados abren transacciones
 * anidadas (savepoints). Una segunda petición concurrente espera el bloqueo
 * y recibe la misma factura. Una factura borrada (suave) no cuenta como
 * vigente: la venta puede facturarse de nuevo (ver active_sale_id).
 */
class InvoiceSaleService
{
    public function __construct(
        private readonly SaleWorkflow $saleWorkflow,
        private readonly SaleBillingReadinessService $readiness,
        private readonly SaleToInvoiceConverter $converter,
        private readonly InvoiceWorkflow $invoiceWorkflow,
    ) {}

    /** @return array{invoice: Invoice, created: bool} */
    public function invoice(Sale $sale, ?int $userId): array
    {
        return DB::transaction(function () use ($sale, $userId): array {
            $locked = Sale::withoutGlobalScope(CompanyScope::class)
                ->whereKey($sale->getKey())
                ->where('company_id', $sale->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = Invoice::withoutGlobalScope(CompanyScope::class)
                ->where('sale_id', $locked->id)
                ->where('company_id', $locked->company_id)
                ->first();

            if ($existing !== null) {
                $invoice = $existing->status === InvoiceStatus::Draft
                    ? $this->invoiceWorkflow->markReady($existing)
                    : $existing;

                return ['invoice' => $invoice, 'created' => false];
            }

            if ($locked->status === SaleStatus::Cancelled) {
                throw new SaleCancelledException($locked);
            }

            $readiness = $this->readiness->evaluate($locked, beforeInvoicing: true);

            if (! $readiness['ready']) {
                throw new SaleNotBillableException($readiness['errors']);
            }

            if ($locked->status === SaleStatus::Draft) {
                $locked = $this->saleWorkflow->submit($locked);
            }

            if ($locked->status === SaleStatus::Pending) {
                $locked = $this->saleWorkflow->confirm($locked);
            }

            $invoice = $this->converter->convert($locked, $userId);

            return ['invoice' => $this->invoiceWorkflow->markReady($invoice), 'created' => true];
        });
    }
}
