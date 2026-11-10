<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.34 (2): varios impuestos por producto y por línea.
 *
 * - `product_taxes`: los impuestos que se copian al agregar el producto a
 *   una línea. No reutiliza las columnas viejas de `products` (iva,
 *   iva_retenido, ieps, isr, impuesto_local...), que quedan congeladas.
 * - `quote_item_taxes`, `sale_item_taxes` e `invoice_item_taxes`: los
 *   impuestos de cada línea, con su base y su importe. Los de la factura son
 *   el snapshot fiscal inmutable que se envía al PAC.
 * - `tax`: clave de c_Impuesto (001 ISR, 002 IVA, 003 IEPS); `kind`:
 *   traslado o retencion; `factor`: tasa, cuota o exento; `rate`: valor de
 *   c_TasaOCuota con 6 decimales (nulo si es exento); `ieps_mode`: sólo para
 *   IEPS (6.34, D1: sum_before_taxes).
 * - `base` y `amount` con 6 decimales (lo que admite el Anexo 20 por
 *   concepto); `amount_rounded` con 2, el que suman los totales.
 * - `taxes_transferred` y `taxes_withheld` en documentos y líneas. `tax_total`
 *   no cambia de significado: es la suma de los trasladados.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string}> tabla => [padre, tabla del padre] */
    private const TAX_TABLES = [
        'product_taxes' => ['product_id', 'products'],
        'quote_item_taxes' => ['quote_item_id', 'quote_items'],
        'sale_item_taxes' => ['sale_item_id', 'sale_items'],
        'invoice_item_taxes' => ['invoice_item_id', 'invoice_items'],
    ];

    private const TOTAL_TABLES = ['quotes', 'quote_items', 'sales', 'sale_items', 'invoices', 'invoice_items'];

    public function up(): void
    {
        foreach (self::TAX_TABLES as $table => [$parent, $parentTable]) {
            Schema::create($table, function (Blueprint $blueprint) use ($table, $parent, $parentTable): void {
                $blueprint->id();
                $blueprint->foreignId('company_id')->constrained('companies')->restrictOnDelete();
                $blueprint->foreignId($parent)->constrained($parentTable)->cascadeOnDelete();
                $blueprint->string('tax', 3);
                $blueprint->string('kind', 20);
                $blueprint->string('factor', 20);
                $blueprint->decimal('rate', 12, 6)->nullable();
                $blueprint->string('ieps_mode', 30)->nullable();
                $blueprint->unsignedTinyInteger('position')->default(0);
                if ($table !== 'product_taxes') {
                    $blueprint->decimal('base', 18, 6)->default(0);
                    $blueprint->decimal('amount', 18, 6)->nullable();
                    $blueprint->decimal('amount_rounded', 14, 2)->default(0);
                }
                $blueprint->timestamps();
                $blueprint->unique([$parent, 'tax', 'kind', 'factor'], "{$table}_unique_tax");
                $blueprint->index(['company_id', $parent]);
            });
        }

        foreach (self::TOTAL_TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->decimal('taxes_transferred', 14, 2)->default(0)->after('tax_total');
                $blueprint->decimal('taxes_withheld', 14, 2)->default(0)->after('taxes_transferred');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TOTAL_TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn(['taxes_transferred', 'taxes_withheld']);
            });
        }

        foreach (array_reverse(array_keys(self::TAX_TABLES)) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
