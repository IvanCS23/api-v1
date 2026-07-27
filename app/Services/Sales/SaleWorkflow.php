<?php

namespace App\Services\Sales;

use App\Enums\SaleStatus;
use App\Exceptions\WorkflowTransitionException;
use App\Models\Client;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Scopes\CompanyScope;
use Illuminate\Support\Facades\DB;

/**
 * Única fuente de verdad de las transiciones de estado de una Sale.
 *
 * Confirmed y Cancelled son terminales (Sale::isEditable() ya lo refleja
 * para edición de datos/líneas; aquí se refleja para transiciones de
 * estado). `confirmed_at`/`cancelled_at` los fija exclusivamente este
 * servicio — nunca el endpoint genérico de update (ver
 * UpdateSaleRequest, Fase 4 §5).
 *
 * Concurrencia (auditoría Fase 4 — cierre): cada transición ocurre
 * dentro de DB::transaction(), y lo primero que hace es releer la Sale
 * con lockForUpdate() — nunca valida sobre la instancia que recibió como
 * parámetro (esa pudo cargarse antes de que otra request ganara una
 * carrera). Todas las validaciones (transición permitida, líneas,
 * cliente, totales coherentes) se evalúan sobre esa relectura bloqueada,
 * y es esa misma instancia la que se actualiza y se retorna — así dos
 * llamadas concurrentes a la misma acción sobre la misma Sale nunca
 * pueden producir un doble efecto: la segunda, al adquirir el lock
 * después de que la primera hizo commit, ve el estado ya cambiado y
 * falla su propia validación de transición.
 *
 * No duplica el cálculo de totales: `confirm()` solo verifica que los
 * totales ya almacenados sean coherentes con las líneas actuales
 * (deberían serlo siempre, ya que SaleItemController recalcula en cada
 * mutación de línea); no vuelve a calcular ni a corregir nada.
 */
class SaleWorkflow
{
    public function submit(Sale $sale): Sale
    {
        return DB::transaction(function () use ($sale): Sale {
            $locked = $this->lockedSale($sale);

            $this->assertTransition($locked, [SaleStatus::Draft], 'enviar a revisión (Pending)');

            $locked->update(['status' => SaleStatus::Pending]);

            return $locked;
        });
    }

    public function confirm(Sale $sale): Sale
    {
        return DB::transaction(function () use ($sale): Sale {
            $locked = $this->lockedSale($sale);

            $this->assertTransition($locked, [SaleStatus::Pending], 'confirmar');
            $this->assertConfirmable($locked);

            $locked->forceFill([
                'status' => SaleStatus::Confirmed,
                'confirmed_at' => now(),
            ])->save();

            return $locked;
        });
    }

    public function cancel(Sale $sale): Sale
    {
        return DB::transaction(function () use ($sale): Sale {
            $locked = $this->lockedSale($sale);

            $this->assertTransition($locked, [SaleStatus::Draft, SaleStatus::Pending], 'cancelar');

            $locked->forceFill([
                'status' => SaleStatus::Cancelled,
                'cancelled_at' => now(),
            ])->save();

            return $locked;
        });
    }

    /**
     * Relee la Sale con lockForUpdate() dentro de la transacción activa.
     * `withoutGlobalScope(CompanyScope::class)` porque lo que se necesita
     * es bloquear la fila exacta sin depender de que CurrentTenant siga
     * vigente en ese instante — pero el filtro por `company_id` NO se
     * elimina: se reafirma explícitamente con el company_id de la propia
     * instancia recibida (ya tenant-scoped y autorizada por el
     * controller antes de llegar aquí, nunca desde el request). Así,
     * aunque withoutGlobalScope() por sí solo permitiría bloquear
     * cualquier fila de cualquier empresa, el `where('company_id', ...)`
     * explícito impide que este método bloquee o modifique una Sale que
     * no pertenezca a la empresa de la instancia original.
     */
    private function lockedSale(Sale $sale): Sale
    {
        return Sale::withoutGlobalScope(CompanyScope::class)
            ->whereKey($sale->getKey())
            ->where('company_id', $sale->company_id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @param  array<int, SaleStatus>  $allowedFrom
     */
    private function assertTransition(Sale $sale, array $allowedFrom, string $actionLabel): void
    {
        if (! in_array($sale->status, $allowedFrom, true)) {
            throw new WorkflowTransitionException(sprintf(
                'No se puede %s una venta en estado "%s".',
                $actionLabel,
                $sale->status->value,
            ));
        }
    }

    private function assertConfirmable(Sale $sale): void
    {
        $items = SaleItem::withoutGlobalScope(CompanyScope::class)
            ->where('sale_id', $sale->id)
            ->get();

        if ($items->isEmpty()) {
            throw new WorkflowTransitionException('No se puede confirmar una venta sin líneas.');
        }

        $client = $sale->client_id !== null
            ? Client::withoutGlobalScope(CompanyScope::class)->find($sale->client_id)
            : null;

        if ($client === null) {
            throw new WorkflowTransitionException('No se puede confirmar una venta sin un cliente válido.');
        }

        $expectedSubtotal = round((float) $items->sum('subtotal'), 2);
        $expectedDiscount = round((float) $items->sum('discount'), 2);
        $expectedTax = round((float) $items->sum('tax_total'), 2);
        $expectedTotal = round((float) $items->sum('total'), 2);

        $coherent = round((float) $sale->subtotal, 2) === $expectedSubtotal
            && round((float) $sale->discount_total, 2) === $expectedDiscount
            && round((float) $sale->tax_total, 2) === $expectedTax
            && round((float) $sale->total, 2) === $expectedTotal;

        if (! $coherent) {
            throw new WorkflowTransitionException('No se puede confirmar una venta con importes incoherentes respecto a sus líneas.');
        }
    }
}
