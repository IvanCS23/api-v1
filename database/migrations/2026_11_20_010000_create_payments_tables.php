<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.35: pagos recibidos de facturas PPD y su complemento de pago (REP,
 * CFDI tipo P). Sólo MXN (D1), sólo contra facturas PPD timbradas (D2), un
 * REP por pago (D4), serie propia PAG (D6).
 *
 * - `payments`: el pago y su CFDI tipo P (campos del PAC como las facturas).
 *   El estado `cancelled` sólo se fija cuando el PAC confirma la cancelación
 *   aceptada; mientras esté pendiente o si se rechaza, el pago sigue
 *   contando para el saldo (ajuste 1).
 * - `payment_allocations`: a qué factura se aplica el pago, con su
 *   parcialidad, saldo anterior, monto pagado y saldo insoluto (6 decimales)
 *   y el snapshot del documento relacionado.
 * - `payment_allocation_taxes`: los impuestos de la factura prorrateados al
 *   monto pagado (misma forma que invoice_item_taxes, Fase 6.34).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('folio', 20);
            $table->string('status', 20)->default('draft');
            $table->dateTime('payment_date');
            $table->string('payment_form', 2);
            $table->string('currency', 3)->default('MXN');
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->decimal('amount', 18, 6);
            $table->string('operation_number', 100)->nullable();
            $table->text('notes')->nullable();

            // Snapshot del receptor al emitir el REP.
            $table->string('client_name')->nullable();
            $table->string('client_rfc', 13)->nullable();
            $table->string('client_regimen_fiscal', 3)->nullable();
            $table->string('client_codigo_postal', 5)->nullable();

            // PAC (como invoices).
            $table->string('pac_provider', 30)->nullable();
            $table->string('pac_environment', 10)->nullable();
            $table->string('pac_organization_id', 64)->nullable();
            $table->string('pac_external_id', 64)->nullable();
            $table->string('pac_idempotency_key', 120)->nullable();
            $table->string('pac_issue_status', 30)->nullable();
            $table->unsignedSmallInteger('pac_issue_attempts')->default(0);
            $table->string('pac_status', 30)->nullable();
            $table->text('pac_response')->nullable();
            $table->text('pac_last_error')->nullable();
            $table->string('cfdi_uuid', 40)->nullable();
            $table->timestamp('stamped_at')->nullable();
            $table->timestamp('issued_at')->nullable();

            // Cancelación del REP.
            $table->string('cancellation_status', 20)->nullable();
            $table->string('cancellation_motive', 2)->nullable();
            $table->timestamp('cancellation_requested_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'folio']);
            $table->unique('pac_external_id');
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'client_id']);
        });

        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->unsignedInteger('installment')->nullable();
            $table->decimal('previous_balance', 18, 6)->nullable();
            $table->decimal('amount', 18, 6);
            $table->decimal('remaining_balance', 18, 6)->nullable();

            // Snapshot del documento relacionado al emitir el REP.
            $table->string('invoice_uuid', 40)->nullable();
            $table->string('invoice_series', 25)->nullable();
            $table->unsignedBigInteger('invoice_folio_number')->nullable();
            $table->string('invoice_currency', 3)->nullable();
            $table->decimal('invoice_total', 18, 6)->nullable();

            $table->timestamps();

            $table->unique(['payment_id', 'invoice_id']);
            $table->index(['company_id', 'invoice_id']);
        });

        Schema::create('payment_allocation_taxes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('payment_allocation_id')->constrained('payment_allocations')->cascadeOnDelete();
            $table->string('tax', 3);
            $table->string('kind', 20);
            $table->string('factor', 20);
            $table->decimal('rate', 12, 6)->nullable();
            $table->string('ieps_mode', 30)->nullable();
            $table->unsignedTinyInteger('position')->default(0);
            $table->decimal('base', 18, 6)->default(0);
            $table->decimal('amount', 18, 6)->nullable();
            $table->decimal('amount_rounded', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['payment_allocation_id', 'tax', 'kind', 'factor', 'rate'], 'payment_allocation_taxes_unique_tax');
            $table->index(['company_id', 'payment_allocation_id'], 'payment_allocation_taxes_company_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocation_taxes');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
    }
};
