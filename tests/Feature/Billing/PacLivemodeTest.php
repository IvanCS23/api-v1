<?php

use App\Data\Billing\PacInvoiceRequest;
use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Exceptions\Billing\PacUnexpectedEnvironmentException;
use App\Exceptions\Billing\PacUnexpectedResponseException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Fase 6.31 (5): el livemode de cada respuesta debe ser el del ambiente con
 * el que se opera, en ambos sentidos (Test → false, Live → true).
 */
const PHASE_631_LM_LIVE_KEY = 'sk_live_PHASE_631_LIVEMODE_KEY_01';
const PHASE_631_LM_UUID = 'BBBBBBBB-1111-4222-8333-444444444444';

beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'billing.pac_mode' => 'test',
    ]);
    Http::preventStrayRequests();
    Storage::fake('local');
});

/** @return array<string, array{0: Closure, 1: bool}> método => [llamada, exige livemode] */
function phase631LivemodeCalls(string $environment): array
{
    if ($environment === 'live') {
        config(['billing.pac_mode' => 'live', 'services.facturapi.test_key' => PHASE_631_LM_LIVE_KEY]);
        app()['env'] = 'production';
    } else {
        config(['services.facturapi.test_key' => 'sk_test_PHASE_631_LIVEMODE_KEY_1']);
    }

    $company = Company::factory()->create();
    app(CurrentTenant::class)->set($company->id);
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    $request = new PacInvoiceRequest($invoice, 'erp-invoice:1:1:v1', 'company-1-invoice-1');
    $provider = facturapiProviderFromConfig($environment);

    return [
        'stampDraftInvoice' => [fn () => $provider->stampDraftInvoice('inv_1'), true],
        'retrieveInvoice' => [fn () => $provider->retrieveInvoice('inv_1'), true],
        'cancelInvoice' => [fn () => $provider->cancelInvoice('inv_1', '02', null), true],
        'createDraftInvoice' => [fn () => $provider->createDraftInvoice($request), true],
        'retrieveDraftInvoice' => [fn () => $provider->retrieveDraftInvoice('inv_1'), true],
        'updateDraftInvoice' => [fn () => $provider->updateDraftInvoice('inv_1', $request), true],
        'createInvoice' => [fn () => $provider->createInvoice($request), false],
        'findInvoiceByExternalId' => [fn () => $provider->findInvoiceByExternalId('company-1-invoice-1'), false],
        'findInvoicesBySeriesFolio' => [fn () => $provider->findInvoicesBySeriesFolio('FAC', 1), false],
    ];
}

function phase631LivemodeFake(?bool $livemode): void
{
    $object = array_filter([
        'id' => 'inv_1',
        'status' => 'draft',
        'is_ready_to_stamp' => true,
        'total' => 100,
    ]) + ($livemode === null ? [] : ['livemode' => $livemode]);

    Http::fake(function (Request $request) use ($object) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        return $request->method() === 'GET' && $path === '/v2/invoices'
            ? Http::response(['data' => [$object], 'total_results' => 1, 'total_pages' => 1, 'page' => 1])
            : Http::response($object);
    });
}

test('el livemode de otro ambiente se rechaza en cada mapeo, en ambos sentidos', function (string $environment) {
    $wrong = $environment === 'test';
    phase631LivemodeFake($wrong);

    foreach (phase631LivemodeCalls($environment) as $name => [$call]) {
        try {
            $call();
            test()->fail("{$name} aceptó livemode=".var_export($wrong, true)." en {$environment}");
        } catch (PacUnexpectedEnvironmentException $e) {
            expect($e->expectedLivemode)->toBe($environment === 'live')
                ->and($e->getMessage())->toContain($environment === 'live' ? 'LIVE' : 'TEST');
        }
    }
})->with(['test', 'live']);

test('el livemode del ambiente se acepta', function (string $environment) {
    phase631LivemodeFake($environment === 'live');

    foreach (phase631LivemodeCalls($environment) as $name => [$call]) {
        $call();
    }

    expect(Http::recorded())->toHaveCount(9);
})->with(['test', 'live']);

test('sin livemode fallan los mapeos que ya lo exigían y se toleran los tres que no lo revisaban', function (string $environment) {
    phase631LivemodeFake(null);

    foreach (phase631LivemodeCalls($environment) as $name => [$call, $required]) {
        if (! $required) {
            $call();

            continue;
        }

        expect($call)->toThrow(PacUnexpectedResponseException::class, 'livemode');
    }
})->with(['test', 'live']);

/** @return array{Company, User, Invoice} */
function phase631LiveReadyInvoice(): array
{
    $company = Company::factory()->withFacturapiOrganization('sk_test_PHASE_631_LM_TEST_KEY_01')->create();
    $user = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Admin]);
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Ready,
        'subtotal' => 100,
        'tax_total' => 0,
        'total' => 100,
        'pac_environment' => null,
    ]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id, 'subtotal' => 100, 'total' => 100]);
    phase631MakeLive($company->id, PHASE_631_LM_LIVE_KEY);
    phase631ProductionReadyOrganization($company);
    app(CurrentTenant::class)->clear();

    return [$company, $user, $invoice];
}

function phase631LiveRemoteFake(string $draftId, bool $livemode = true): void
{
    Http::fake(function (Request $request) use ($draftId, $livemode) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $draft = ['id' => $draftId, 'status' => 'draft', 'livemode' => $livemode, 'is_ready_to_stamp' => true, 'total' => 100];
        $valid = ['id' => $draftId, 'status' => 'valid', 'livemode' => $livemode, 'uuid' => PHASE_631_LM_UUID, 'stamp' => ['date' => '2026-08-14T12:00:00Z']];

        return match (true) {
            $request->method() === 'POST' && $path === '/v2/invoices' => Http::response($draft),
            $request->method() === 'GET' && $path === "/v2/invoices/{$draftId}" => Http::response($valid),
            $request->method() === 'POST' && $path === "/v2/invoices/{$draftId}/stamp" => Http::response($valid),
            $request->method() === 'GET' && $path === "/v2/invoices/{$draftId}/xml" => Http::response('<?xml version="1.0" encoding="UTF-8"?><cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" Version="4.0"><cfdi:Complemento><tfd:TimbreFiscalDigital xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" Version="1.1" UUID="'.PHASE_631_LM_UUID.'" SelloCFD="X"/></cfdi:Complemento></cfdi:Comprobante>'),
            $request->method() === 'GET' && $path === "/v2/invoices/{$draftId}/pdf" => Http::response("%PDF-1.4\nfake\n%%EOF"),
            default => Http::response([], 599),
        };
    });
}

test('una empresa Live emite con su llave Live y la factura queda fijada en live', function () {
    [, $user, $invoice] = phase631LiveReadyInvoice();
    $draftId = 'inv_631_live_'.$invoice->id;

    Http::fake(function (Request $request) use ($draftId) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $draft = ['id' => $draftId, 'status' => 'draft', 'livemode' => true, 'is_ready_to_stamp' => true, 'total' => 100];

        return match (true) {
            $request->method() === 'POST' && $path === '/v2/invoices' => Http::response($draft),
            $request->method() === 'GET' && $path === "/v2/invoices/{$draftId}" => Http::response($draft),
            $request->method() === 'POST' && $path === "/v2/invoices/{$draftId}/stamp" => Http::response(['id' => $draftId, 'status' => 'valid', 'livemode' => true, 'uuid' => PHASE_631_LM_UUID, 'stamp' => ['date' => '2026-08-14T12:00:00Z']]),
            default => Http::response([], 599),
        };
    });

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/operations/issue", ['confirm' => true])
        ->assertOk()
        ->assertJsonPath('pac.status', 'valid')
        ->assertJsonPath('pac.mode', 'live');

    expect(DB::table('invoices')->where('id', $invoice->id)->value('pac_environment'))->toBe('live');
    Http::assertSent(fn (Request $request): bool => $request->header('Authorization')[0] === 'Bearer '.PHASE_631_LM_LIVE_KEY);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->header('Authorization')[0] ?? '', 'sk_test_'));
});

test('una empresa Live no persiste un timbrado con livemode=false', function () {
    [, $user, $invoice] = phase631LiveReadyInvoice();
    phase631LiveRemoteFake('inv_631_wrong_'.$invoice->id, livemode: false);

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/operations/issue", ['confirm' => true])
        ->assertStatus(502);

    $row = DB::table('invoices')->where('id', $invoice->id)->first();
    expect($row->status)->toBe('ready')
        ->and($row->pac_draft_external_id)->toBeNull()
        ->and($row->cfdi_uuid)->toBeNull();
});

test('al terminar el paro de emergencia XML/PDF y reconciliación se recuperan', function () {
    [$company, $user, $invoice] = phase631LiveReadyInvoice();
    $draftId = 'inv_631_recover_'.$invoice->id;
    DB::table('invoices')->where('id', $invoice->id)->update([
        'status' => 'issued',
        'pac_environment' => 'live',
        'pac_provider' => 'facturapi',
        'pac_external_id' => $draftId,
        'cfdi_uuid' => PHASE_631_LM_UUID,
        'pac_status' => 'valid',
        'pac_issue_status' => 'succeeded',
        'cancellation_status' => 'none',
        'pac_reconciliation_required' => true,
    ]);
    phase631LiveRemoteFake($draftId);

    // Paro de emergencia: nada contacta al PAC ni cambia la factura.
    config(['billing.pac_mode' => 'test']);

    foreach (['pac/reconcile', 'pac/artifacts'] as $action) {
        $this->actingAs($user, 'api')->postJson("/api/invoices/{$invoice->id}/{$action}")->assertStatus(503);
    }

    Http::assertNothingSent();
    expect(DB::table('invoices')->where('id', $invoice->id)->value('cfdi_artifacts_status'))->toBeNull();

    // Fin del paro: ambas operaciones se recuperan con la llave Live.
    config(['billing.pac_mode' => 'live']);

    $this->actingAs($user, 'api')->postJson("/api/invoices/{$invoice->id}/pac/reconcile")
        ->assertOk()
        ->assertJsonPath('pac.reconciliation_required', false);
    $this->actingAs($user, 'api')->postJson("/api/invoices/{$invoice->id}/pac/artifacts")
        ->assertOk()
        ->assertJsonPath('cfdi.artifacts.status', 'stored');

    Http::assertSent(fn (Request $request): bool => $request->header('Authorization')[0] === 'Bearer '.PHASE_631_LM_LIVE_KEY);
});
