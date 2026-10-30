<?php

namespace App\Services\Billing;

use App\Contracts\Billing\PacProviderResolver;
use App\Exceptions\Billing\PacFolioAlreadyUsedException;
use App\Models\Invoice;
use App\Support\Billing\InvoiceFolio;
use App\Support\Billing\PacIdentifiers;
use Illuminate\Support\Facades\Log;

/**
 * Fase 6.28: antes de emitir, consulta en Facturapi si la organización ya
 * usa la serie y el folio de la factura en otro comprobante. Facturapi no
 * documenta que valide folios duplicados; el ERP garantiza la unicidad
 * entre sus propias facturas, pero no frente a lo emitido por fuera (panel
 * de Facturapi, otro sistema con la misma organización).
 *
 * La llaman los orquestadores, nunca los servicios de borrador:
 * OrchestrateInvoiceIssuanceService antes de la transición ERP a `issued`
 * (un duplicado deja la factura en `ready`, sin cambios) e
 * IssueInvoiceToPacService cuando se usa directo (`/pac/issue`), antes de
 * crear o actualizar el borrador.
 *
 * Los comprobantes de esta misma factura (su borrador o su emisión
 * directa, identificados por external_id) no cuentan. Es una lectura: si
 * falla, la excepción se propaga y no se escribe nada.
 */
class CfdiFolioAvailability
{
    public function __construct(private readonly PacProviderResolver $pacProviders) {}

    public function assertAvailable(Invoice $invoice): void
    {
        if (! config('billing.cfdi_folio_check')) {
            return;
        }

        $folio = InvoiceFolio::parse($invoice->folio);

        // Sin folio válido el readiness ya bloqueó la emisión.
        if ($folio === null) {
            return;
        }

        $own = [
            PacIdentifiers::draftExternalId((int) $invoice->company_id, (int) $invoice->id),
            PacIdentifiers::externalId((int) $invoice->company_id, (int) $invoice->id),
        ];

        $foreign = array_filter(
            $this->pacProviders->forInvoice($invoice)->findInvoicesBySeriesFolio($folio->series, $folio->number),
            fn (array $match): bool => ! in_array($match['external_id'], $own, true),
        );

        if ($foreign === []) {
            return;
        }

        Log::warning('billing.invoice.cfdi_folio_already_used', [
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'cfdi_folio' => $folio->cfdiLabel(),
            'matches' => count($foreign),
            'statuses' => array_values(array_unique(array_map(fn (array $match): ?string => $match['status'], $foreign))),
        ]);

        throw new PacFolioAlreadyUsedException((int) $invoice->id, $folio->cfdiLabel(), count($foreign));
    }
}
