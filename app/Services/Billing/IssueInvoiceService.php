<?php

namespace App\Services\Billing;

use App\Contracts\Billing\PacProvider;
use App\Enums\InvoiceStatus;
use App\Events\Billing\InvoiceIssued;
use App\Exceptions\InvoiceAlreadyIssuedException;
use App\Exceptions\InvoiceCannotBeIssuedException;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ReflectionClass;

/**
 * Primera emisión controlada de un CFDI (Fase 6.2, entorno TEST
 * únicamente): recibe una Invoice ya creada e Issued, la envía al PAC
 * activo mediante el contrato PacProvider, y persiste el resultado.
 *
 * No conoce Facturapi ni ningún detalle de un PAC concreto — depende
 * únicamente de PacProvider, inyectado por el contenedor (ver
 * BillingServiceProvider). El nombre de proveedor persistido en
 * `pac_provider` se deriva por reflexión del nombre corto de la clase
 * concreta resuelta (ej. FacturapiProvider -> "facturapi"), no de una
 * constante ni de config propio de Facturapi — así sustituir el binding
 * por otro PAC no requiere tocar esta clase.
 *
 * Toda la persistencia ocurre dentro de una única transacción; si
 * cualquier excepción ocurre durante ese paso, la transacción hace
 * rollback completo (comportamiento nativo de DB::transaction()) y la
 * Invoice nunca queda parcialmente emitida. La llamada HTTP al PAC
 * ocurre deliberadamente FUERA de la transacción (no se debe sostener un
 * lock de fila durante una llamada de red).
 */
class IssueInvoiceService
{
    public function __construct(private readonly PacProvider $pacProvider) {}

    public function issue(Invoice $invoice): Invoice
    {
        $current = $this->requireCurrentTenantInvoice($invoice);

        $this->assertIssuable($current);
        $this->assertNotAlreadyIssued($current);

        $providerSlug = $this->resolveProviderSlug();

        $startedAt = microtime(true);
        $result = $this->pacProvider->createInvoice($current);
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        $updated = DB::transaction(function () use ($current, $result, $providerSlug): Invoice {
            $locked = Invoice::withoutGlobalScope(CompanyScope::class)
                ->whereKey($current->getKey())
                ->where('company_id', $current->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertNotAlreadyIssued($locked);

            $locked->forceFill([
                'pac_provider' => $providerSlug,
                'pac_external_id' => $result->externalId,
                'cfdi_uuid' => $result->uuid,
                'pac_status' => $result->status,
                'stamped_at' => $result->stampedAt,
                'last_pac_sync_at' => now(),
                'pac_response' => $result->rawResponse,
                'pac_last_error' => null,
            ])->save();

            return $locked;
        });

        Log::info('billing.invoice.pac_issued', [
            'invoice_id' => $updated->id,
            'company_id' => $updated->company_id,
            'pac_provider' => $updated->pac_provider,
            'pac_external_id' => $updated->pac_external_id,
            'elapsed_ms' => $elapsedMs,
        ]);

        event(new InvoiceIssued($updated, $result));

        return $updated;
    }

    /**
     * Relee la Invoice scoped explícitamente por el CurrentTenant activo
     * (nunca por el company_id de la instancia recibida, que pudo llegar
     * manipulada). Si no hay tenant activo, o la Invoice no existe/no
     * pertenece a ese tenant, se trata exactamente igual que "no existe"
     * — mismo criterio fail-closed que CompanyScope, nunca distingue
     * "existe pero es de otra empresa" de "no existe".
     */
    private function requireCurrentTenantInvoice(Invoice $invoice): Invoice
    {
        $tenantId = app(CurrentTenant::class)->id();

        $fresh = $tenantId !== null
            ? Invoice::withoutGlobalScope(CompanyScope::class)
                ->whereKey($invoice->getKey())
                ->where('company_id', $tenantId)
                ->first()
            : null;

        if ($fresh === null) {
            throw (new ModelNotFoundException())->setModel(Invoice::class, [$invoice->getKey()]);
        }

        return $fresh;
    }

    private function assertIssuable(Invoice $invoice): void
    {
        if ($invoice->status !== InvoiceStatus::Issued) {
            throw new InvoiceCannotBeIssuedException($invoice);
        }
    }

    private function assertNotAlreadyIssued(Invoice $invoice): void
    {
        if ($invoice->cfdi_uuid !== null || $invoice->pac_external_id !== null) {
            throw new InvoiceAlreadyIssuedException($invoice);
        }
    }

    private function resolveProviderSlug(): string
    {
        $shortName = (new ReflectionClass($this->pacProvider))->getShortName();

        $slug = str_ends_with($shortName, 'Provider')
            ? substr($shortName, 0, -strlen('Provider'))
            : $shortName;

        return strtolower($slug);
    }
}
