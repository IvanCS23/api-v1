<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceItemResource;
use App\Models\Invoice;

/**
 * Solo lectura: las líneas de una Invoice nunca se crean/editan/eliminan
 * manualmente — siempre las genera SaleToInvoiceConverter al convertir
 * (ver InvoiceItemPolicy, sin métodos create/update/delete).
 */
class InvoiceItemController extends Controller
{
    public function index($invoiceId)
    {
        $invoice = Invoice::findOrFail($invoiceId);

        $this->authorize('view', $invoice);

        return InvoiceItemResource::collection($invoice->items()->get());
    }

    public function show($invoiceId, $itemId)
    {
        $invoice = Invoice::findOrFail($invoiceId);

        $this->authorize('view', $invoice);

        $item = $invoice->items()->findOrFail($itemId);

        $this->authorize('view', $item);

        return new InvoiceItemResource($item);
    }
}
