<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.25 — Una venta cuya factura se borró puede facturarse de nuevo.
 *
 * `invoices` usa borrado suave, pero el índice único `erp_invoices_sale_unique`
 * sobre `sale_id` seguía contando la factura borrada: SaleToInvoiceConverter
 * no la veía (el scope de SoftDeletes la excluye), pasaba su comprobación y
 * el INSERT chocaba con el índice (error 500). La venta quedaba confirmada
 * sin forma de facturarse.
 *
 * La unicidad pasa a una columna generada virtual `active_sale_id`
 * (`sale_id` mientras la factura no esté borrada; NULL si lo está). Una
 * venta sigue teniendo como máximo una factura vigente y el historial de
 * las borradas se conserva. Funciona en MariaDB/MySQL y en SQLite (pruebas).
 *
 * `sale_id` conserva su llave foránea con un índice simple propio (antes
 * la respaldaba el índice único).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->index('sale_id', 'erp_invoices_sale_index');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropUnique('erp_invoices_sale_unique');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('active_sale_id')
                ->nullable()
                ->virtualAs('case when deleted_at is null then sale_id end')
                ->after('sale_id');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->unique('active_sale_id', 'erp_invoices_active_sale_unique');
        });
    }

    /**
     * Restaurar el único sobre sale_id falla si para entonces una venta ya
     * tiene una factura borrada y otra vigente: es el caso que esta
     * migración habilita. Habría que resolver esos duplicados antes.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropUnique('erp_invoices_active_sale_unique');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('active_sale_id');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->unique('sale_id', 'erp_invoices_sale_unique');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropIndex('erp_invoices_sale_index');
        });
    }
};
