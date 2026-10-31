<?php

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Fase 6.29: la emisión empresarial prepara el borrador remoto ANTES de
 * pasar la factura de ready a issued. Si Facturapi no lo da por listo para
 * timbrar (aun después de actualizarlo), o el remoto está pendiente o
 * cancelado, la factura sigue en ready y nunca se llama /stamp.
 */
beforeEach(function () {
    facturapiOrganizationsFromConfigKey();
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_PHASE_629_DRAFT',
    ]);
    Http::preventStrayRequests();
});

/** @return array{User, Invoice} */
function phase629ReadyInvoice(): array
{
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Admin]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Ready, 'subtotal' => 100, 'tax_total' => 0, 'total' => 100]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id, 'subtotal' => 100, 'total' => 100]);

    return [$user, $invoice->fresh()];
}

/**
 * El PAC responde todo borrador con $status / $ready (por referencia: una
 * prueba puede cambiarlos entre llamadas) y timbra si se le pide.
 */
function phase629FakeDraft(Invoice $invoice, string &$status, bool &$ready): string
{
    $draftId = 'inv_prep_'.$invoice->id;

    Http::fake(function (Request $request) use ($draftId, &$status, &$ready) {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/stamp')) {
            return Http::response(['id' => $draftId, 'status' => 'valid', 'livemode' => false, 'uuid' => 'EEEEEEEE-1111-4222-8333-444444444444', 'stamp' => ['date' => '2026-10-30T10:00:00']]);
        }

        return Http::response(array_filter(['id' => $draftId, 'status' => $status, 'livemode' => false, 'is_ready_to_stamp' => $status === 'draft' ? $ready : null, 'total' => 100], fn ($v) => $v !== null));
    });

    return $draftId;
}

function phase629Methods(): array
{
    return collect(Http::recorded())->map(fn (array $pair) => $pair[0]->method().' '.parse_url($pair[0]->url(), PHP_URL_PATH))->all();
}

function phase629Issue(User $user, Invoice $invoice)
{
    app(CurrentTenant::class)->clear();

    return test()->actingAs($user, 'api')->postJson("/api/invoices/{$invoice->id}/operations/issue", ['confirm' => true]);
}

test('un borrador que no queda listo para timbrar deja la factura en ready, sin /stamp', function () {
    [$user, $invoice] = phase629ReadyInvoice();
    [$status, $ready] = ['draft', false];
    $draftId = phase629FakeDraft($invoice, $status, $ready);

    phase629Issue($user, $invoice)->assertStatus(409)->assertJsonPath('code', 'PAC_DRAFT_NOT_READY');

    $row = (array) DB::table('invoices')->where('id', $invoice->id)->first();
    expect($row['status'])->toBe(InvoiceStatus::Ready->value)
        ->and($row['issued_at'])->toBeNull()
        ->and($row['pac_issue_status'])->toBeNull()
        ->and($row['pac_draft_external_id'])->toBe($draftId)
        // Se crea, y al no estar listo se sincroniza y se actualiza con el snapshot vigente.
        ->and(phase629Methods())->toBe(['POST /v2/invoices', 'GET /v2/invoices/'.$draftId, 'PUT /v2/invoices/'.$draftId])
        ->and(collect(phase629Methods())->contains(fn (string $call) => str_ends_with($call, '/stamp')))->toBeFalse();
});

test('un borrador remoto pendiente o cancelado se rechaza sin transicionar', function (string $remote, int $status, string $code) {
    [$user, $invoice] = phase629ReadyInvoice();
    $ready = false;
    phase629FakeDraft($invoice, $remote, $ready);

    phase629Issue($user, $invoice)->assertStatus($status)->assertJsonPath('code', $code);

    expect(DB::table('invoices')->where('id', $invoice->id)->value('status'))->toBe(InvoiceStatus::Ready->value);
})->with([
    'pendiente' => ['pending', 409, 'PAC_ISSUANCE_IN_PROGRESS'],
    'cancelado' => ['canceled', 409, 'PAC_RESOURCE_CANCELED'],
]);

test('un borrador listo se emite con las mismas llamadas de antes: crear, sincronizar y timbrar', function () {
    [$user, $invoice] = phase629ReadyInvoice();
    [$status, $ready] = ['draft', true];
    $draftId = phase629FakeDraft($invoice, $status, $ready);

    phase629Issue($user, $invoice)->assertOk()->assertJsonPath('status', 'issued')->assertJsonPath('pac.status', 'valid');

    expect(phase629Methods())->toBe(['POST /v2/invoices', 'GET /v2/invoices/'.$draftId, 'POST /v2/invoices/'.$draftId.'/stamp']);
});

test('tras quedar en ready por un borrador no listo, el reintento lo vuelve a revisar antes de transicionar', function () {
    [$user, $invoice] = phase629ReadyInvoice();
    [$status, $ready] = ['draft', false];
    $draftId = phase629FakeDraft($invoice, $status, $ready);
    phase629Issue($user, $invoice)->assertStatus(409);
    $before = count(Http::recorded());

    $ready = true;
    phase629Issue($user, $invoice)->assertOk()->assertJsonPath('status', 'issued');

    // Sincroniza el borrador (ya listo), y el timbrado vuelve a sincronizar antes de /stamp.
    expect(array_slice(phase629Methods(), $before))->toBe(['GET /v2/invoices/'.$draftId, 'GET /v2/invoices/'.$draftId, 'POST /v2/invoices/'.$draftId.'/stamp']);
});
