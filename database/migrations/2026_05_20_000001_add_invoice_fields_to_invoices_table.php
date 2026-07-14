<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'invoice_number')) {
                $table->string('invoice_number')->unique()->after('id');
            }

            if (! Schema::hasColumn('invoices', 'status')) {
                $table->string('status')->default('created')->after('invoice_number');
            }

            if (! Schema::hasColumn('invoices', 'document_type')) {
                $table->string('document_type')->default('factura')->after('status');
            }

            if (! Schema::hasColumn('invoices', 'emitter_name')) {
                $table->string('emitter_name')->after('document_type');
            }

            if (! Schema::hasColumn('invoices', 'emitter_zip')) {
                $table->string('emitter_zip', 10)->after('emitter_name');
            }

            if (! Schema::hasColumn('invoices', 'client_id')) {
                $table->foreignId('client_id')->nullable()->after('emitter_zip')->constrained('clients')->nullOnDelete();
            }

            if (! Schema::hasColumn('invoices', 'client_snapshot')) {
                $table->json('client_snapshot')->nullable()->after('client_id');
            }

            if (! Schema::hasColumn('invoices', 'payment')) {
                $table->json('payment')->nullable()->after('client_snapshot');
            }

            if (! Schema::hasColumn('invoices', 'options')) {
                $table->json('options')->nullable()->after('payment');
            }

            if (! Schema::hasColumn('invoices', 'items')) {
                $table->json('items')->after('options');
            }

            if (! Schema::hasColumn('invoices', 'subtotal')) {
                $table->decimal('subtotal', 14, 2)->default(0)->after('items');
            }

            if (! Schema::hasColumn('invoices', 'taxes')) {
                $table->decimal('taxes', 14, 2)->default(0)->after('subtotal');
            }

            if (! Schema::hasColumn('invoices', 'discount')) {
                $table->decimal('discount', 14, 2)->default(0)->after('taxes');
            }

            if (! Schema::hasColumn('invoices', 'total')) {
                $table->decimal('total', 14, 2)->default(0)->after('discount');
            }

            if (! Schema::hasColumn('invoices', 'currency')) {
                $table->string('currency', 80)->default('MXN - Peso Mexicano')->after('total');
            }

            if (! Schema::hasColumn('invoices', 'exchange_rate')) {
                $table->decimal('exchange_rate', 14, 6)->nullable()->after('currency');
            }

            if (! Schema::hasColumn('invoices', 'issued_at')) {
                $table->timestamp('issued_at')->nullable()->after('exchange_rate');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn([
                'invoice_number',
                'status',
                'document_type',
                'emitter_name',
                'emitter_zip',
                'client_snapshot',
                'payment',
                'options',
                'items',
                'subtotal',
                'taxes',
                'discount',
                'total',
                'currency',
                'exchange_rate',
                'issued_at',
            ]);
        });
    }
};
