<?php

use App\Enums\InvoiceHealthStatus;
use App\Enums\InvoicePacEventType;
use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\InvoiceHealthEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Fase 6.27 (5/7): billing:health ante facturas de otra organización de
 * Facturapi y su archivado explícito.
 */
const PHASE_627_HEALTH_ORG = 'org_health_current';
const PHASE_627_HEALTH_OTHER = 'org_health_previous';

beforeEach(function () {
    config(['billing.pac_mode' => 'test']);
    Http::preventStrayRequests();
});

function phase627HealthCompany(): Company
{
    return Company::factory()->withFacturapiOrganization('sk_test_HEALTH_COMPANY_KEY', PHASE_627_HEALTH_ORG)->create();
}

/** Factura timbrada sin pendientes (artifacts guardados): sana si la organización coincide. */
function phase627HealthInvoice(Company $company, ?string $organization, array $extra = []): Invoice
{
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    $invoice->forceFill(array_merge([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_health_'.$invoice->id,
        'cfdi_uuid' => fake()->uuid(),
        'pac_status' => 'valid',
        'cancellation_status' => 'none',
        'pac_issue_status' => 'succeeded',
        'pac_idempotency_key' => "erp-invoice:{$company->id}:{$invoice->id}:v1",
        'pac_reconciliation_required' => false,
        'stamped_at' => now()->subDay(),
        'last_pac_sync_at' => now()->subDay(),
        'cfdi_artifacts_status' => 'stored',
        'cfdi_xml_path' => 'cfdi/x.xml',
        'cfdi_pdf_path' => 'cfdi/x.pdf',
        'pac_last_error' => null,
    ], $extra))->save();
    DB::table('invoices')->where('id', $invoice->id)->update(['pac_organization_id' => $organization]);

    return $invoice->fresh();
}

function phase627HealthCodes(Invoice $invoice, ?string $companyOrganization): array
{
    $report = app(InvoiceHealthEvaluator::class)->evaluate($invoice, CarbonImmutable::now(), null, $companyOrganization);

    return [$report->status->value, $report->codes()];
}

test('una factura de otra organización es inválida y sólo reporta la organización', function () {
    $company = phase627HealthCompany();
    $invoice = phase627HealthInvoice($company, PHASE_627_HEALTH_OTHER, ['cfdi_artifacts_status' => null, 'cfdi_xml_path' => null, 'cfdi_pdf_path' => null]);

    expect(phase627HealthCodes($invoice, PHASE_627_HEALTH_ORG))->toBe(['invalid', ['pac_organization_mismatch']]);
});

test('archivada queda como informativa y no arrastra sus otros hallazgos', function () {
    $company = phase627HealthCompany();
    $invoice = phase627HealthInvoice($company, PHASE_627_HEALTH_OTHER, ['cfdi_artifacts_status' => null, 'cfdi_xml_path' => null, 'cfdi_pdf_path' => null]);
    DB::table('invoices')->where('id', $invoice->id)->update(['pac_organization_archived_at' => now()]);

    expect(phase627HealthCodes($invoice->fresh(), PHASE_627_HEALTH_ORG))->toBe(['valid', ['pac_organization_archived']]);
});

test('con la organización de la empresa o sin organización registrada no hay hallazgo de organización', function (?string $organization) {
    $company = phase627HealthCompany();
    $invoice = phase627HealthInvoice($company, $organization);

    [, $codes] = phase627HealthCodes($invoice, PHASE_627_HEALTH_ORG);

    expect($codes)->not->toContain('pac_organization_mismatch')
        ->and($codes)->not->toContain('pac_organization_archived');
})->with([
    'misma organización' => [PHASE_627_HEALTH_ORG],
    'sin organización registrada' => [null],
]);

test('billing:health cuenta la de otra organización como inválida y la archivada como informativa', function () {
    $company = phase627HealthCompany();
    $other = phase627HealthInvoice($company, PHASE_627_HEALTH_OTHER);
    $archived = phase627HealthInvoice($company, PHASE_627_HEALTH_OTHER);
    DB::table('invoices')->where('id', $archived->id)->update(['pac_organization_archived_at' => now()]);

    $exit = Artisan::call('billing:health', ['--format' => 'json', '--company' => $company->id]);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($report['by_code']['pac_organization_mismatch'])->toBe(1)
        ->and($report['by_code']['pac_organization_archived'])->toBe(1)
        ->and($report['totals'][InvoiceHealthStatus::Invalid->value])->toBe(1)
        ->and(collect($report['invoices'])->pluck('id')->all())->toBe([$other->id]);
    Http::assertNothingSent();
});

test('archivar: dry-run no escribe; --apply archiva sin tocar datos fiscales y registra el evento', function () {
    $company = phase627HealthCompany();
    $invoice = phase627HealthInvoice($company, PHASE_627_HEALTH_OTHER);
    DB::table('invoices')->where('id', $invoice->id)->update(['updated_at' => '2026-10-01 00:00:00']);
    $fiscal = fn () => (array) DB::table('invoices')->where('id', $invoice->id)
        ->first(['status', 'cfdi_uuid', 'pac_status', 'cancellation_status', 'pac_external_id', 'pac_organization_id', 'updated_at']);
    $before = $fiscal();
    $options = ['--invoice' => [$invoice->id], '--reason' => 'Organización de desarrollo anterior, sin acceso'];

    expect(Artisan::call('billing:archive-inaccessible-invoices', $options))->toBe(0);
    $dry = Artisan::output();

    expect($dry)->toContain('dry-run')
        ->and($dry)->toContain('se archiva')
        ->and($dry)->toContain(PHASE_627_HEALTH_OTHER)
        ->and(DB::table('invoices')->whereNotNull('pac_organization_archived_at')->count())->toBe(0);

    expect(Artisan::call('billing:archive-inaccessible-invoices', $options + ['--apply' => true]))->toBe(0);

    $row = DB::table('invoices')->where('id', $invoice->id)->first();
    expect($row->pac_organization_archived_at)->not->toBeNull()
        ->and($row->pac_organization_archive_reason)->toBe('Organización de desarrollo anterior, sin acceso')
        ->and($fiscal())->toBe($before)
        ->and(DB::table('invoice_pac_events')->where('invoice_id', $invoice->id)->where('event_type', InvoicePacEventType::OrganizationArchived->value)->count())->toBe(1);

    // Repetirlo no duplica nada.
    expect(Artisan::call('billing:archive-inaccessible-invoices', $options + ['--apply' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('ya está archivada')
        ->and(DB::table('invoice_pac_events')->where('event_type', InvoicePacEventType::OrganizationArchived->value)->count())->toBe(1);
});

test('archivar es todo o nada: una factura de la organización actual o desconocida bloquea el lote', function (?string $organization, string $message) {
    $company = phase627HealthCompany();
    $other = phase627HealthInvoice($company, PHASE_627_HEALTH_OTHER);
    $blocked = phase627HealthInvoice($company, $organization);

    $exit = Artisan::call('billing:archive-inaccessible-invoices', ['--invoice' => [$other->id, $blocked->id], '--reason' => 'x', '--apply' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain($message)
        ->and(DB::table('invoices')->whereNotNull('pac_organization_archived_at')->count())->toBe(0);
})->with([
    'organización actual' => [PHASE_627_HEALTH_ORG, 'es la organización de la empresa'],
    'organización desconocida' => [null, 'organización desconocida'],
]);

test('archivar exige facturas y motivo, y rechaza IDs inexistentes', function () {
    expect(Artisan::call('billing:archive-inaccessible-invoices', ['--reason' => 'x']))->toBe(1)
        ->and(Artisan::call('billing:archive-inaccessible-invoices', ['--invoice' => [1]]))->toBe(1)
        ->and(Artisan::call('billing:archive-inaccessible-invoices', ['--invoice' => [999999], '--reason' => 'x', '--apply' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('no existe');
});

test('la línea de tiempo muestra el motivo del archivado', function () {
    $company = phase627HealthCompany();
    $user = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Admin]);
    $invoice = phase627HealthInvoice($company, PHASE_627_HEALTH_OTHER);
    Artisan::call('billing:archive-inaccessible-invoices', ['--invoice' => [$invoice->id], '--reason' => 'Organización anterior', '--apply' => true]);

    $response = $this->actingAs($user, 'api')->getJson("/api/invoices/{$invoice->id}/billing")->assertOk();

    expect($response->json('pac.organization_status'))->toBe('archived')
        ->and($response->json('pac.organization_archived_at'))->not->toBeNull()
        ->and(collect($response->json('timeline'))->firstWhere('type', 'organization_archived')['context'])->toBe(['reason' => 'Organización anterior']);
});
