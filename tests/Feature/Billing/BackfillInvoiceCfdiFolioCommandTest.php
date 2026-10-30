<?php

use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Fase 6.28: billing:backfill-cfdi-folio llena la serie y el folio del CFDI
 * desde la respuesta guardada. Sin HTTP; dry-run por defecto.
 */
function phase628Stamped(Company $company, string $folio, ?array $pacResponse): Invoice
{
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'folio' => $folio, 'status' => InvoiceStatus::Issued]);
    // saveQuietly: sin el hook del modelo, como una factura anterior a las columnas.
    $invoice->forceFill(['pac_external_id' => 'inv_'.$invoice->id, 'pac_response' => $pacResponse])->saveQuietly();

    return $invoice;
}

test('dry-run reporta sin escribir; --apply llena sólo las dos columnas y es idempotente', function () {
    $company = Company::factory()->create();
    $legacy = phase628Stamped($company, 'FAC-00000019', ['id' => 'a', 'series' => 'F', 'folio_number' => 10]);
    $current = phase628Stamped($company, 'FAC-00000020', ['id' => 'b', 'series' => 'FAC', 'folio_number' => 20]);
    $missing = phase628Stamped($company, 'FAC-00000021', ['id' => 'c']);
    $draftOnly = Invoice::factory()->create(['company_id' => $company->id, 'folio' => 'FAC-00000022']);
    DB::table('invoices')->update(['cfdi_series' => null, 'cfdi_folio_number' => null, 'updated_at' => '2026-10-01 00:00:00']);

    expect(Artisan::call('billing:backfill-cfdi-folio'))->toBe(0);
    $dry = Artisan::output();

    expect($dry)->toContain('dry-run')
        ->and($dry)->toContain('F-10')
        ->and($dry)->toContain('no (anterior a la 6.28)')
        ->and($dry)->toContain('FAC-20')
        ->and($dry)->toContain('(sin folio guardado)')
        ->and($dry)->toContain('Por llenar: 2')
        ->and($dry)->toContain('Sin folio en la respuesta guardada: 1')
        ->and(DB::table('invoices')->whereNotNull('cfdi_folio_number')->count())->toBe(0);

    expect(Artisan::call('billing:backfill-cfdi-folio', ['--apply' => true]))->toBe(0);

    expect((array) DB::table('invoices')->where('id', $legacy->id)->first(['cfdi_series', 'cfdi_folio_number']))->toBe(['cfdi_series' => 'F', 'cfdi_folio_number' => 10])
        ->and((array) DB::table('invoices')->where('id', $current->id)->first(['cfdi_series', 'cfdi_folio_number']))->toBe(['cfdi_series' => 'FAC', 'cfdi_folio_number' => 20])
        ->and(DB::table('invoices')->where('id', $missing->id)->value('cfdi_folio_number'))->toBeNull()
        ->and(DB::table('invoices')->where('id', $draftOnly->id)->value('cfdi_folio_number'))->toBeNull()
        ->and(DB::table('invoices')->where('updated_at', '<>', '2026-10-01 00:00:00')->count())->toBe(0);

    Artisan::call('billing:backfill-cfdi-folio', ['--apply' => true]);
    expect(Artisan::output())->toContain('Llenadas: 0');
});

test('respeta --company y nunca sobrescribe un folio ya registrado', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $invoiceA = phase628Stamped($companyA, 'FAC-00000001', ['id' => 'a', 'series' => 'F', 'folio_number' => 1]);
    $invoiceB = phase628Stamped($companyB, 'FAC-00000001', ['id' => 'b', 'series' => 'F', 'folio_number' => 2]);
    DB::table('invoices')->where('id', $invoiceA->id)->update(['cfdi_series' => 'FAC', 'cfdi_folio_number' => 1]);
    DB::table('invoices')->where('id', $invoiceB->id)->update(['cfdi_series' => null, 'cfdi_folio_number' => null]);

    Artisan::call('billing:backfill-cfdi-folio', ['--apply' => true, '--company' => [$companyA->id]]);

    expect(DB::table('invoices')->where('id', $invoiceA->id)->value('cfdi_series'))->toBe('FAC')
        ->and(DB::table('invoices')->where('id', $invoiceB->id)->value('cfdi_folio_number'))->toBeNull();
});
