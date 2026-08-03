<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Exceptions\SaleAlreadyInvoicedException;
use App\Exceptions\SaleNotBillableException;
use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Scopes\CompanyScope;
use App\Models\TaxRate;
use Illuminate\Support\Facades\DB;

/**
 * Convierte una Sale (debe estar Confirmed y con billing-readiness
 * `ready=true`) en una Invoice nueva, con snapshot fiscal completo de
 * cliente y líneas. La Sale original nunca se modifica.
 *
 * Concurrencia (mismo patrón endurecido que QuoteToSaleConverter /
 * SaleWorkflow / QuoteWorkflow — Fase 4): todo ocurre dentro de
 * DB::transaction(); la Sale se vuelve a leer con lockForUpdate() y
 * `where('company_id', $sale->company_id)` explícito (con el company_id
 * de la instancia ya tenant-scoped recibida, nunca del request) antes de
 * decidir nada. Ya con el lock, se verifica que no exista ya una Invoice
 * para esa Sale (`invoices.sale_id` es único) — si existe, se lanza
 * SaleAlreadyInvoicedException sin crear nada. Solo entonces se evalúa
 * SaleBillingReadinessService sobre la Sale bloqueada; si `ready=false`,
 * se lanza SaleNotBillableException con los errores tal cual, sin crear
 * nada.
 *
 * Integridad de company_id: la Invoice y cada InvoiceItem toman su
 * company_id directamente del company_id de la Sale bloqueada
 * (asignación directa de atributo antes de save(), el mecanismo de
 * confianza que reconoce BelongsToCompany) — nunca de CurrentTenant ni
 * de ningún dato de la petición.
 *
 * `payment_form`/`payment_method` (Fase 6.2.2): se copian de
 * `Company.default_payment_form`/`default_payment_method` — la única
 * fuente de este dato fiscal en el dominio hoy (Sale no lo posee
 * directamente). Si la empresa no los configuró, quedan null en el
 * snapshot; nunca se inventa un valor por defecto aquí.
 */
class SaleToInvoiceConverter
{
    public function __construct(
        private readonly SaleBillingReadinessService $billingReadiness,
        private readonly InvoiceNumberGenerator $numberGenerator,
    ) {}

    public function convert(Sale $sale, ?int $createdBy = null): Invoice
    {
        return DB::transaction(function () use ($sale, $createdBy): Invoice {
            $lockedSale = Sale::withoutGlobalScope(CompanyScope::class)
                ->whereKey($sale->getKey())
                ->where('company_id', $sale->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            // Limitada explícitamente por sale_id Y company_id (auditoría
            // Fase 5 — cierre): sale_id ya es único a nivel de esquema, así
            // que filtrar también por company_id es redundante en la
            // práctica, pero es la misma defensa en profundidad aplicada en
            // el resto del dominio — nunca confiar en un solo filtro sin
            // acotar explícitamente por la empresa de la instancia bloqueada.
            $alreadyInvoiced = Invoice::withoutGlobalScope(CompanyScope::class)
                ->where('sale_id', $lockedSale->id)
                ->where('company_id', $lockedSale->company_id)
                ->exists();

            if ($alreadyInvoiced) {
                throw new SaleAlreadyInvoicedException($lockedSale);
            }

            $readiness = $this->billingReadiness->evaluate($lockedSale);

            if (! $readiness['ready']) {
                throw new SaleNotBillableException($readiness['errors']);
            }

            $client = Client::withoutGlobalScope(CompanyScope::class)->findOrFail($lockedSale->client_id);

            // `payment_form`/`payment_method` (Fase 6.2.2): Sale no posee
            // este dato fiscal directamente — la única fuente en el
            // dominio hoy es el default a nivel de Company
            // (`default_payment_form`/`default_payment_method`, ver su
            // migración). Company no está tenant-scoped (no usa
            // BelongsToCompany), así que no requiere withoutGlobalScope.
            // Si la empresa no configuró su default, el snapshot queda
            // en null — nunca se inventa aquí un valor fijo.
            $company = Company::findOrFail($lockedSale->company_id);

            $invoice = new Invoice([
                'client_id' => $client->id,
                'status' => InvoiceStatus::Draft,
                'subtotal' => $lockedSale->subtotal,
                'discount_total' => $lockedSale->discount_total,
                'tax_total' => $lockedSale->tax_total,
                'total' => $lockedSale->total,
                'currency' => $lockedSale->currency,
                'notes' => $lockedSale->notes,
                'client_name' => $client->name,
                'client_rfc' => $client->rfc,
                'client_regimen_fiscal' => $client->regimen_fiscal,
                'client_uso_cfdi' => $client->uso_cfdi,
                'client_codigo_postal' => $client->codigo_postal,
                'client_calle' => $client->calle,
                'client_no_exterior' => $client->no_exterior,
                'client_no_interior' => $client->no_interior,
                'client_colonia' => $client->colonia,
                'client_localidad' => $client->localidad,
                'client_municipio' => $client->municipio,
                'client_estado' => $client->estado,
                'client_pais' => $client->pais,
                'payment_form' => $company->default_payment_form,
                'payment_method' => $company->default_payment_method,
            ]);
            $invoice->company_id = $lockedSale->company_id;
            $invoice->sale_id = $lockedSale->id;
            $invoice->created_by = $createdBy;
            $invoice->folio = $this->numberGenerator->next($lockedSale->company_id);
            $invoice->save();

            $saleItems = SaleItem::withoutGlobalScope(CompanyScope::class)
                ->where('sale_id', $lockedSale->id)
                ->get();

            foreach ($saleItems as $saleItem) {
                $product = $saleItem->product_id !== null
                    ? Product::withoutGlobalScope(CompanyScope::class)->find($saleItem->product_id)
                    : null;
                $taxRate = $saleItem->tax_rate_id !== null
                    ? TaxRate::find($saleItem->tax_rate_id)
                    : null;

                $item = new InvoiceItem([
                    'product_id' => $saleItem->product_id,
                    'tax_rate_id' => $saleItem->tax_rate_id,
                    'description' => $saleItem->description,
                    'quantity' => $saleItem->quantity,
                    'unit_price' => $saleItem->unit_price,
                    'discount' => $saleItem->discount,
                    'subtotal' => $saleItem->subtotal,
                    'tax_total' => $saleItem->tax_total,
                    'total' => $saleItem->total,
                    'product_clave_producto' => $product?->clave_producto,
                    'product_clave_unidad' => $product?->clave_unidad,
                    'product_type' => $product?->type?->value,
                    'product_no_identificacion' => $product?->no_identificacion,
                    'product_description' => $product?->descripcion,
                    'product_objeto_imp' => $product?->objeto_imp,
                    'tax_code' => $taxRate?->code,
                    'tax_name' => $taxRate?->name,
                    'tax_rate_value' => $taxRate?->rate,
                    'tax_type' => $taxRate?->tax_type?->value,
                    'tax_factor_type' => $taxRate?->factor_type?->value,
                ]);
                $item->company_id = $invoice->company_id;
                $item->invoice_id = $invoice->id;
                $item->save();
            }

            return $invoice;
        });
    }
}
