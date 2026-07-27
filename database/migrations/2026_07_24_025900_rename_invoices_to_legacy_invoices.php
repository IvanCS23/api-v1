<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 5 — Invoice Domain.
 *
 * El nombre `invoices` ya lo ocupaba el sistema legado de tickets (MVP
 * pre-multiempresa: sin `company_id`, columnas JSON tipo ticket, sin
 * relación real con Sale/Client fiscal). Este era exactamente el
 * escenario que ya anticipaba ERP_ARCHITECTURE_PLAN.md §5: renombrar el
 * legado antes de construir la `Invoice` real. Es un rename puro — cero
 * pérdida de datos, la fila real existente sobrevive intacta bajo el
 * nuevo nombre.
 *
 * `App\Models\Invoice` → `App\Models\LegacyInvoice`,
 * `InvoiceController` → `LegacyInvoiceController`,
 * rutas `/api/invoices` → `/api/legacy-invoices`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('invoices', 'legacy_invoices');
    }

    public function down(): void
    {
        Schema::rename('legacy_invoices', 'invoices');
    }
};
