<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.2.2 — Snapshot fiscal de forma/método de pago.
 *
 * Auditoría contra docs.facturapi.io/api/ (Create Invoice, POST
 * /v2/invoices): `payment_form` es un campo REQUERIDO — string de
 * exactamente 2 caracteres, catálogo SAT c_FormaPago (ej. "01" Efectivo,
 * "03" Transferencia). `payment_method` SÍ existe en el mismo endpoint,
 * pero es OPCIONAL — string enum "PUE"|"PPD" (SAT c_MetodoPago), default
 * "PUE" si se omite. Ninguno de los dos ya existía como snapshot en
 * Invoice — separado por completo de `payment_channels` (catálogo
 * interno de cobro del ERP, explícitamente no fiscal, ver su propio
 * docblock).
 *
 * `payment_method` se agrega igualmente como columna (aunque opcional en
 * Facturapi) porque el propio endpoint SÍ lo trata como dato fiscal
 * (catálogo SAT c_MetodoPago) — se deja nullable y sin validación
 * obligatoria; si es null, FacturapiProvider simplemente no lo incluye
 * en el payload y dela que Facturapi aplique su default documentado
 * ("PUE"), sin que este proyecto invente ni fije ese valor.
 *
 * Tamaños: `payment_form` es 2 caracteres (código numérico SAT). *
 * `payment_method` es 3 caracteres ("PUE"/"PPD", un enum de texto, no un
 * código numérico) — nótese que la columna ya existente
 * `companies.default_payment_method` está dimensionada a 2 caracteres
 * (ver 2026_06_29_000000_create_companies_table.php), lo cual no alcanza
 * para "PUE"/"PPD"; no se corrige aquí (esa migración ya se ejecutó y
 * está fuera del alcance de esta fase) — ver reporte de entrega, riesgos.
 *
 * No se ejecuta realmente en este sprint (solo `migrate --pretend`). No
 * se modifica `create_invoices_table` ni ninguna otra migración ya
 * existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('payment_form', 2)->nullable()->after('client_pais');
            $table->string('payment_method', 3)->nullable()->after('payment_form');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn(['payment_form', 'payment_method']);
        });
    }
};
