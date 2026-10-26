<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.25 — Forma y método de pago por venta.
 *
 * Hasta ahora la factura copiaba siempre los predeterminados de la empresa
 * (`companies.default_payment_form` / `default_payment_method`). La venta
 * pasa a guardar su propio valor; la empresa queda como respaldo en
 * SaleToInvoiceConverter (ver App\Support\Billing\SalePaymentRules).
 *
 * Nullable y sin backfill: las ventas existentes siguen usando el respaldo
 * de la empresa. Mismos tamaños que en companies (forma 2, método 3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->string('payment_form', 2)->nullable()->after('currency');
            $table->string('payment_method', 3)->nullable()->after('payment_form');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropColumn(['payment_form', 'payment_method']);
        });
    }
};
