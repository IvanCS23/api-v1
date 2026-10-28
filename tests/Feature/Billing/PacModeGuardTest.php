<?php

use App\Data\Billing\PacInvoiceRequest;
use App\Enums\InvoicePacEventType;
use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Exceptions\Billing\PacAuthenticationException;
use App\Exceptions\Billing\PacConfigurationException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Support\Billing\PacCredentialGuard;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Fase 6.19: la llave del PAC debe corresponder a billing.pac_mode antes
 * de cualquier HTTP; sólo el modo test está soportado.
 */
beforeEach(function () {
    facturapiOrganizationsFromConfigKey();
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_PHASE_619_SECRET',
        'billing.pac_mode' => 'test',
    ]);
    Http::preventStrayRequests();
    Http::fake();
});

function phase619Invoice(array $attributes = []): array
{
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Admin]);
    $invoice = Invoice::factory()->create(array_merge([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Ready,
        'subtotal' => 100,
        'tax_total' => 0,
        'total' => 100,
    ], $attributes));
    InvoiceItem::factory()->create([
        'company_id' => $company->id,
        'invoice_id' => $invoice->id,
        'subtotal' => 100,
        'total' => 100,
    ]);
    app(CurrentTenant::class)->set($company->id);

    return [$company, $user, $invoice->fresh(['items'])];
}

/** Configuraciones inválidas: [pac_mode, llave, motivo esperado]. */
dataset('invalid pac configurations', [
    'llave live en modo test' => ['test', 'sk_live_PHASE_619_SECRET', 'pac_key_mode_mismatch'],
    'llave sin prefijo' => ['test', 'PHASE_619_SECRET', 'pac_key_mode_mismatch'],
    'llave vacia' => ['test', '', 'pac_key_missing'],
    'llave nula' => ['test', null, 'pac_key_missing'],
    'modo live no soportado' => ['live', 'sk_live_PHASE_619_SECRET', 'pac_mode_unsupported'],
    'modo desconocido' => ['sandbox', 'sk_test_PHASE_619_SECRET', 'pac_mode_unsupported'],
]);

test('el guard acepta solo modo test con llave sk_test_', function () {
    expect((new PacCredentialGuard('test', 'sk_test_abc'))->violation())->toBeNull();
});

test('el guard rechaza combinaciones invalidas con un motivo estable', function (?string $mode, ?string $key, string $reason) {
    expect((new PacCredentialGuard($mode, $key))->violation())->toBe($reason);
})->with('invalid pac configurations');

test('el provider no envia ninguna peticion con configuracion invalida, en escrituras, consultas y descargas', function (?string $mode, ?string $key, string $reason) {
    config(['billing.pac_mode' => $mode, 'services.facturapi.test_key' => $key]);
    [, , $invoice] = phase619Invoice();
    $request = new PacInvoiceRequest($invoice, 'erp-invoice:1:1:v1', 'company-1-invoice-1');
    $provider = facturapiProviderFromConfig();

    $calls = [
        'createInvoice' => fn () => $provider->createInvoice($request),
        'retrieveInvoice' => fn () => $provider->retrieveInvoice('inv_1'),
        'findInvoiceByExternalId' => fn () => $provider->findInvoiceByExternalId('company-1-invoice-1'),
        'createDraftInvoice' => fn () => $provider->createDraftInvoice($request),
        'retrieveDraftInvoice' => fn () => $provider->retrieveDraftInvoice('inv_1'),
        'updateDraftInvoice' => fn () => $provider->updateDraftInvoice('inv_1', $request),
        'stampDraftInvoice' => fn () => $provider->stampDraftInvoice('inv_1'),
        'cancelInvoice' => fn () => $provider->cancelInvoice('inv_1', '02', null),
        'downloadPdf' => fn () => $provider->downloadPdf('inv_1'),
        'downloadXml' => fn () => $provider->downloadXml('inv_1'),
        'downloadCancellationReceiptXml' => fn () => $provider->downloadCancellationReceiptXml('inv_1'),
        'downloadCancellationReceiptPdf' => fn () => $provider->downloadCancellationReceiptPdf('inv_1'),
    ];

    foreach ($calls as $name => $call) {
        try {
            $call();
            test()->fail("{$name} no debió ejecutarse");
        } catch (PacConfigurationException $e) {
            expect($e->pacCode)->toBe($reason)
                ->and($e->getMessage())->not->toContain('PHASE_619_SECRET');
        }
    }

    Http::assertNothingSent();
})->with('invalid pac configurations');

test('la configuracion invalida es un fallo definitivo, no ambiguo', function () {
    expect(new PacConfigurationException('pac_key_mode_mismatch'))
        ->toBeInstanceOf(PacAuthenticationException::class);
});

test('operations/issue con llave live no transiciona el ERP ni contacta al PAC', function () {
    config(['services.facturapi.test_key' => 'sk_live_PHASE_619_SECRET']);
    [, $user, $invoice] = phase619Invoice();
    app(CurrentTenant::class)->clear();

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/operations/issue", ['confirm' => true])
        ->assertStatus(503)
        ->assertJsonPath('code', 'PAC_CONFIGURATION_INVALID')
        ->assertJsonMissingPath('pac_code');

    expect(DB::table('invoices')->where('id', $invoice->id)->value('status'))->toBe('ready')
        ->and(DB::table('invoices')->where('id', $invoice->id)->value('pac_issue_status'))->toBeNull();
    Http::assertNothingSent();
});

test('operations/cancel fiscal con modo live no registra solicitud ni contacta al PAC', function () {
    config(['billing.pac_mode' => 'live', 'services.facturapi.test_key' => 'sk_live_PHASE_619_SECRET']);
    [, $user, $invoice] = phase619Invoice(['status' => InvoiceStatus::Issued]);
    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_phase619_'.$invoice->id,
        'cfdi_uuid' => 'CCCCCCCC-1111-4222-8333-444444444444',
        'pac_status' => 'valid',
        'pac_issue_status' => 'succeeded',
        'cancellation_status' => 'none',
    ])->save();
    app(CurrentTenant::class)->clear();

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/operations/cancel", ['confirm' => true, 'motive' => '02'])
        ->assertStatus(503)
        ->assertJsonPath('code', 'PAC_CONFIGURATION_INVALID');

    expect(DB::table('invoices')->where('id', $invoice->id)->value('cancellation_status'))->toBe('none')
        ->and(DB::table('invoice_pac_events')
            ->where('event_type', InvoicePacEventType::CancellationRequested->value)
            ->count())->toBe(0);
    Http::assertNothingSent();
});

dataset('facturapi test commands', [
    'billing:facturapi-test-cancellation-identity-audit',
    'billing:facturapi-test-cancel',
    'billing:facturapi-test-artifacts',
    'billing:facturapi-test-cancellation-receipt',
    'billing:facturapi-test-draft',
    'billing:facturapi-test-draft-sync',
    'billing:facturapi-test-draft-update',
    'billing:facturapi-test-cancellation-receipt-inspect',
    'billing:facturapi-test-reconcile',
    'billing:facturapi-test-stamp',
    'billing:facturapi-test-validate',
]);

test('los comandos de prueba solo corren en local/testing', function (string $command) {
    app()->instance('env', 'staging');

    $this->artisan($command, ['invoice' => 1])
        ->expectsOutputToContain('solo se ejecuta en entornos local/testing')
        ->assertExitCode(1);

    Http::assertNothingSent();
})->with('facturapi test commands');

test('los comandos de prueba exigen pac_mode test', function (string $command) {
    config(['billing.pac_mode' => 'live']);

    $this->artisan($command, ['invoice' => 1])
        ->expectsOutputToContain('billing.pac_mode debe ser "test"')
        ->assertExitCode(1);

    Http::assertNothingSent();
})->with('facturapi test commands');

test('los comandos de prueba rechazan una llave live sin mostrarla', function (string $command) {
    // Fase 6.27: la llave es la de la organización de la empresa de la factura.
    config(['services.facturapi.test_key' => 'sk_live_PHASE_619_SECRET']);
    [, , $invoice] = phase619Invoice();
    config(['services.facturapi.test_key' => 'sk_test_PHASE_619_SECRET']);
    app(CurrentTenant::class)->clear();

    $this->artisan($command, ['invoice' => $invoice->id])
        ->expectsOutputToContain('no corresponde al modo TEST')
        ->doesntExpectOutputToContain('PHASE_619_SECRET')
        ->assertExitCode(1);

    Http::assertNothingSent();
})->with('facturapi test commands');
