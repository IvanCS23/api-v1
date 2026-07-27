<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LegacyInvoice;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Renombrado desde `InvoiceController` en Fase 5 (ver
 * `LegacyInvoice`/migración `rename_invoices_to_legacy_invoices`) para
 * liberar el nombre `Invoice` para el nuevo dominio fiscal real. Sin
 * cambios de comportamiento respecto al controller original.
 */
class LegacyInvoiceController extends Controller
{
    public function index()
    {
        return response()->json(LegacyInvoice::latest()->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'document_type' => 'required|string|max:50',
            'emitter_name' => 'required|string|max:255',
            'emitter_zip' => 'required|string|max:10',
            'client_id' => 'nullable|exists:clients,id',
            'client_snapshot' => 'nullable|array',
            'payment' => 'nullable|array',
            'options' => 'nullable|array',
            'items' => 'required|array|min:1',
            'items.*.cantidad' => 'required|numeric|min:0.01',
            'items.*.claves' => 'required|string|max:50',
            'items.*.claveUnidad' => 'nullable|string|max:50',
            'items.*.descripcion' => 'required|string|max:1000',
            'items.*.precio' => 'required|numeric|min:0',
            'items.*.subtotal' => 'required|numeric|min:0',
            'items.*.descuentoTipo' => 'nullable|string|max:10',
            'items.*.descuentoValor' => 'nullable|numeric|min:0',
            'items.*.impuestos' => 'nullable|numeric|min:0',
            'items.*.total' => 'required|numeric|min:0',
            'subtotal' => 'required|numeric|min:0',
            'taxes' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'total' => 'required|numeric|min:0',
            'currency' => 'required|string|max:80',
            'exchange_rate' => 'nullable|numeric|min:0',
            'issued_at' => 'nullable|date',
        ]);

        $invoice = LegacyInvoice::create([
            ...$validated,
            'invoice_number' => $this->nextInvoiceNumber(),
            'status' => 'created',
            'taxes' => $validated['taxes'] ?? 0,
            'discount' => $validated['discount'] ?? 0,
        ]);

        return response()->json($invoice, 201);
    }

    public function show(string $id)
    {
        return response()->json(LegacyInvoice::findOrFail($id));
    }

    public function update(Request $request, string $id)
    {
        $invoice = LegacyInvoice::findOrFail($id);

        $validated = $request->validate([
            'status' => 'sometimes|string|max:50',
            'document_type' => 'sometimes|string|max:50',
            'emitter_name' => 'sometimes|string|max:255',
            'emitter_zip' => 'sometimes|string|max:10',
            'client_id' => 'nullable|exists:clients,id',
            'client_snapshot' => 'nullable|array',
            'payment' => 'nullable|array',
            'options' => 'nullable|array',
            'items' => 'sometimes|array|min:1',
            'subtotal' => 'sometimes|numeric|min:0',
            'taxes' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'total' => 'sometimes|numeric|min:0',
            'currency' => 'sometimes|string|max:80',
            'exchange_rate' => 'nullable|numeric|min:0',
            'issued_at' => 'nullable|date',
        ]);

        $invoice->update($validated);

        return response()->json($invoice);
    }

    public function destroy(string $id)
    {
        LegacyInvoice::findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    private function nextInvoiceNumber(): string
    {
        return 'TCK-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6));
    }
}
