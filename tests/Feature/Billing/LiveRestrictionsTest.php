<?php

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Fase 6.31 (6): en Live no se ofrecen los endpoints históricos que
 * cambian sólo el ERP ni las primitivas /pac/issue y /pac/cancel, y la
 * cancelación sólo admite los motivos 02 y 03. El 04 se retiró también en
 * Test.
 */
const PHASE_631_LR_UUID = 'CCCCCCCC-1111-4222-8333-444444444444';

beforeEach(function () {
    config(['services.facturapi.base_url' => 'https://example-pac.test/v2', 'billing.pac_mode' => 'test']);
    Http::preventStrayRequests();
});

/** @return array{Company, User, Invoice} */
function phase631RestrictedInvoice(string $environment, InvoiceStatus $status = InvoiceStatus::Issued, bool $withCfdi = true): array
{
    $company = Company::factory()->withFacturapiOrganization('sk_test_PHASE_631_LR_TEST_KEY_1')->create();
    $user = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Admin]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => $status, 'pac_environment' => $withCfdi ? 'test' : null]);

    if ($withCfdi) {
        $invoice->forceFill([
            'pac_provider' => 'facturapi',
            'pac_external_id' => 'inv_631_lr_'.$invoice->id,
            'cfdi_uuid' => PHASE_631_LR_UUID,
            'pac_status' => 'valid',
            'pac_issue_status' => 'succeeded',
            'cancellation_status' => 'none',
        ])->save();
    }

    if ($environment === 'live') {
        phase631MakeLive($company->id, 'sk_live_PHASE_631_LR_LIVE_KEY_01', $invoice);
    }

    app(CurrentTenant::class)->clear();

    return [$company, $user, $invoice];
}

test('en Live los endpoints históricos de ERP y las primitivas PAC responden 409 sin tocar nada', function (string $endpoint, InvoiceStatus $status, bool $withCfdi) {
    [, $user, $invoice] = phase631RestrictedInvoice('live', $status, $withCfdi);

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/{$endpoint}", ['confirm' => true, 'motive' => '02'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'LEGACY_OPERATION_UNAVAILABLE');

    $row = DB::table('invoices')->where('id', $invoice->id)->first();
    expect($row->status)->toBe($status->value)
        ->and($row->pac_issue_status)->toBe($withCfdi ? 'succeeded' : null)
        ->and($row->pac_cancel_request_status)->toBeNull();
    Http::assertNothingSent();
})->with([
    'issue ERP' => ['issue', InvoiceStatus::Ready, false],
    'cancel ERP' => ['cancel', InvoiceStatus::Issued, true],
    'pac/issue' => ['pac/issue', InvoiceStatus::Issued, false],
    'pac/cancel' => ['pac/cancel', InvoiceStatus::Issued, true],
]);

test('en Test los endpoints históricos siguen disponibles', function () {
    [, $user, $invoice] = phase631RestrictedInvoice('test', InvoiceStatus::Ready, false);

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/issue")
        ->assertOk();
});

test('en Live sólo se cancela con los motivos 02 y 03', function (string $motive, bool $allowed) {
    [, $user, $invoice] = phase631RestrictedInvoice('live');
    Http::fake(['*' => Http::response([
        'id' => $invoice->pac_external_id, 'status' => 'canceled', 'livemode' => true,
        'uuid' => PHASE_631_LR_UUID, 'cancellation_status' => 'accepted',
    ])]);
    $payload = ['confirm' => true, 'motive' => $motive] + ($motive === '01' ? ['substitution_uuid' => 'DDDDDDDD-1111-4222-8333-444444444444'] : []);

    $response = $this->actingAs($user, 'api')->postJson("/api/invoices/{$invoice->id}/operations/cancel", $payload);

    if ($allowed) {
        $response->assertOk()->assertJsonPath('status', 'cancelled');
        Http::assertSentCount(1);

        return;
    }

    $response->assertStatus(422)->assertJsonPath('code', 'CANCELLATION_MOTIVE_UNAVAILABLE');
    expect(DB::table('invoices')->where('id', $invoice->id)->value('pac_cancel_request_status'))->toBeNull();
    Http::assertNothingSent();
})->with([
    '01' => ['01', false],
    '02' => ['02', true],
    '03' => ['03', true],
    '04' => ['04', false],
]);

test('el motivo 04 se rechaza también en Test y el 01 sigue disponible ahí', function () {
    [, $user, $invoice] = phase631RestrictedInvoice('test');

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/operations/cancel", ['confirm' => true, 'motive' => '04'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'CANCELLATION_MOTIVE_UNAVAILABLE');

    $this->actingAs($user, 'api')
        ->getJson("/api/invoices/{$invoice->id}/billing")
        ->assertJsonPath('actions.cancellation_motives', ['01', '02', '03']);
    Http::assertNothingSent();
});

test('el panel recibe sólo 02 y 03 para una factura Live', function () {
    [, $user, $invoice] = phase631RestrictedInvoice('live');

    $this->actingAs($user, 'api')
        ->getJson("/api/invoices/{$invoice->id}/billing")
        ->assertOk()
        ->assertJsonPath('actions.cancellation_mode', 'pac')
        ->assertJsonPath('actions.cancellation_motives', ['02', '03']);
});

test('legacy-invoices no depende del PAC (evidencia para no restringirlo)', function () {
    $source = (string) file_get_contents(app_path('Http/Controllers/Api/LegacyInvoiceController.php'));

    expect($source)->not->toMatch('/Pac|Facturapi|Billing|Http::|\bInvoice::/');
});
