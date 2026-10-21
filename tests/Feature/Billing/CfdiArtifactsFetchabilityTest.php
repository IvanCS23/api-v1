<?php

use App\Enums\InvoiceHealthAction;
use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Exceptions\Billing\CfdiArtifactMismatchException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\DownloadInvoiceArtifactsService;
use App\Services\Billing\InvoiceBusinessCapabilitiesService;
use App\Services\Billing\InvoiceHealthEvaluator;
use App\Support\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Fase 6.21: una sola regla (InvoiceBusinessCapabilitiesService::
 * artifactsFetchable) decide cuándo se puede obtener XML/PDF; la usan el
 * servicio, la capability can_fetch_artifacts y billing:health. Incluye
 * CFDI cancelados: verificado en Facturapi TEST que /xml y /pdf de un
 * CFDI cancelado responden 200 con el mismo UUID.
 */
beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_PHASE_621_ARTIFACTS',
    ]);

    Storage::fake('local');
    Http::preventStrayRequests();
});

/** @return array{Invoice, User} */
function phase621StampedInvoice(array $overrides = [], UserRole $role = UserRole::Admin): array
{
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'role' => $role]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    $invoice->forceFill(array_merge([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_621_'.$invoice->id,
        'cfdi_uuid' => 'fced601d-c4f6-4ce7-8f05-f3d38de530f9',
        'pac_status' => 'valid',
        'pac_issue_status' => 'succeeded',
        'pac_draft_status' => 'valid',
        'stamped_at' => now(),
    ], $overrides))->save();
    app(CurrentTenant::class)->set($company->id);

    return [$invoice->fresh(), $user];
}

/** @return array<string, mixed> */
function phase621Canceled(array $overrides = []): array
{
    return array_merge([
        'status' => InvoiceStatus::Cancelled,
        'pac_status' => 'canceled',
        'cancellation_status' => 'accepted',
        'pac_cancel_request_status' => 'completed',
    ], $overrides);
}

function phase621FakeArtifacts(Invoice $invoice, ?string $uuid = null): void
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" Version="4.0" Total="116.00">'
        .'<cfdi:Complemento><tfd:TimbreFiscalDigital xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital"'
        .' Version="1.1" UUID="'.($uuid ?? $invoice->cfdi_uuid).'" SelloCFD="SELLO_FAKE"/></cfdi:Complemento>'
        .'</cfdi:Comprobante>';

    Http::fake([
        "*/invoices/{$invoice->pac_external_id}/xml" => Http::response($xml, 200, ['Content-Type' => 'application/xml']),
        "*/invoices/{$invoice->pac_external_id}/pdf" => Http::response("%PDF-1.4\nfake\n%%EOF", 200, ['Content-Type' => 'application/pdf']),
    ]);
}

// ==================== Capability ====================

test('can_fetch_artifacts sigue la regla única de artifactsFetchable', function (array $attributes, bool $expected) {
    [$invoice, $user] = phase621StampedInvoice($attributes);
    $capabilities = app(InvoiceBusinessCapabilitiesService::class);

    expect($capabilities->artifactsFetchable($invoice))->toBe($expected)
        ->and($capabilities->for($invoice, $user)['can_fetch_artifacts'])->toBe($expected);
})->with([
    'CFDI valid sin artifacts' => [[], true],
    'CFDI canceled sin artifacts' => [phase621Canceled(), true],
    'descarga previa failed' => [['cfdi_artifacts_status' => 'failed'], true],
    'descarga previa ambigua' => [['cfdi_artifacts_status' => 'reconciliation_required'], true],
    'artifacts ya almacenados' => [['cfdi_artifacts_status' => 'stored'], false],
    'descarga en curso' => [fn () => ['cfdi_artifacts_status' => 'pending', 'cfdi_artifacts_started_at' => now()], false],
    'emisión pending' => [['pac_status' => 'pending', 'cfdi_uuid' => null], false],
    'sin identidad fiscal' => [['pac_external_id' => null, 'cfdi_uuid' => null, 'pac_status' => null], false],
]);

test('can_fetch_artifacts exige permiso de gestión PAC', function (UserRole $role, bool $expected) {
    [$invoice, $user] = phase621StampedInvoice(role: $role);

    expect(app(InvoiceBusinessCapabilitiesService::class)->for($invoice, $user)['can_fetch_artifacts'])->toBe($expected);
})->with([
    'admin' => [UserRole::Admin, true],
    'accountant' => [UserRole::Accountant, true],
    'sales' => [UserRole::Sales, false],
    'employee' => [UserRole::Employee, false],
]);

// ==================== Servicio ====================

test('descarga y almacena XML/PDF de un CFDI cancelado validando el UUID', function () {
    [$invoice] = phase621StampedInvoice(phase621Canceled());
    phase621FakeArtifacts($invoice);

    app(DownloadInvoiceArtifactsService::class)->download($invoice);

    $fresh = $invoice->fresh();
    expect($fresh->cfdi_artifacts_status)->toBe('stored')
        ->and($fresh->pac_status)->toBe('canceled')
        ->and(Storage::disk('local')->exists($fresh->cfdi_xml_path))->toBeTrue()
        ->and(Storage::disk('local')->exists($fresh->cfdi_pdf_path))->toBeTrue();
});

test('un CFDI cancelado con UUID ajeno en el XML sigue bloqueado', function () {
    [$invoice] = phase621StampedInvoice(phase621Canceled());
    phase621FakeArtifacts($invoice, 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee');

    expect(fn () => app(DownloadInvoiceArtifactsService::class)->download($invoice))
        ->toThrow(CfdiArtifactMismatchException::class);

    expect($invoice->fresh()->cfdi_artifacts_status)->not->toBe('stored');
});

// ==================== API ====================

test('POST /pac/artifacts sobre un CFDI cancelado almacena y apaga can_fetch_artifacts', function () {
    [$invoice, $user] = phase621StampedInvoice(phase621Canceled());
    phase621FakeArtifacts($invoice);
    app(CurrentTenant::class)->clear();

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/pac/artifacts")
        ->assertOk()
        ->assertJsonPath('cfdi.artifacts.xml_available', true)
        ->assertJsonPath('cfdi.artifacts.pdf_available', true)
        ->assertJsonPath('actions.can_fetch_artifacts', false)
        ->assertJsonPath('actions.can_reconcile', false);
});

// ==================== billing:health ====================

test('cfdi_artifacts_missing sólo se sugiere cuando la descarga es posible', function (array $attributes, bool $expected) {
    [$invoice] = phase621StampedInvoice($attributes);

    $finding = app(InvoiceHealthEvaluator::class)
        ->evaluate($invoice, CarbonImmutable::now())
        ->find('cfdi_artifacts_missing');

    expect($finding !== null)->toBe($expected);

    if ($expected) {
        expect($finding->action)->toBe(InvoiceHealthAction::FetchArtifacts)
            ->and($finding->isInformational())->toBeTrue();
    }
})->with([
    'CFDI valid sin artifacts' => [[], true],
    'CFDI canceled sin artifacts (antes se sugería y el servicio lo rechazaba)' => [phase621Canceled(), true],
    'artifacts almacenados' => [['cfdi_artifacts_status' => 'stored'], false],
    'descarga en curso' => [['cfdi_artifacts_status' => 'pending'], false],
]);
