<?php

use App\Enums\InvoiceStatus;
use App\Exceptions\Billing\PacUnexpectedEnvironmentException;
use App\Exceptions\Billing\PacUnexpectedResponseException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Billing\InvoiceWorkflow;
use App\Services\Billing\ReconcileInvoiceWithPacService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_PHASE_64_SECRET',
    ]);

    Http::preventStrayRequests();
});

function phase64Invoice(array $attributes = []): Invoice
{
    $company = $attributes['company'] ?? Company::factory()->create();
    unset($attributes['company']);

    $invoice = Invoice::factory()->create(array_merge([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Issued,
        'folio' => 'FAC-PHASE64',
        'subtotal' => 100,
        'discount_total' => 5,
        'tax_total' => 15.20,
        'total' => 110.20,
        'currency' => 'MXN',
        'client_name' => 'Snapshot Inmutable',
        'client_rfc' => 'AAA010101AAA',
    ], $attributes));

    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_phase64_'.$invoice->id,
        'cfdi_uuid' => 'fced601d-c4f6-4ce7-8f05-f3d38de530f9',
        'pac_status' => 'valid',
        'cancellation_status' => 'none',
        'stamped_at' => '2026-08-01 10:00:00',
        'pac_issue_status' => 'succeeded',
        'pac_reconciliation_required' => false,
        'cfdi_artifacts_status' => 'stored',
    ])->save();

    app(CurrentTenant::class)->set($company->id);

    return $invoice->fresh();
}

function fakePhase64Retrieve(Invoice $invoice, array $overrides = []): void
{
    Http::fake([
        "*/invoices/{$invoice->pac_external_id}" => Http::response(array_merge([
            'id' => $invoice->pac_external_id,
            'livemode' => false,
            'status' => 'valid',
            'uuid' => strtoupper((string) $invoice->cfdi_uuid),
            'cancellation_status' => 'none',
            'stamp' => ['date' => '2026-08-01T10:00:00Z'],
        ], $overrides), 200),
    ]);
}

test('sin tenant activo falla cerrado y no hace HTTP', function () {
    $invoice = phase64Invoice();
    app(CurrentTenant::class)->clear();
    Http::fake();

    expect(fn () => app(ReconcileInvoiceWithPacService::class)->reconcile($invoice))
        ->toThrow(ModelNotFoundException::class);

    Http::assertNothingSent();
});

test('usa GET retrieve con Bearer TEST y acepta exclusivamente livemode false', function () {
    $invoice = phase64Invoice();
    fakePhase64Retrieve($invoice);

    $result = app(ReconcileInvoiceWithPacService::class)->reconcile($invoice);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && $request->url() === "https://example-pac.test/v2/invoices/{$invoice->pac_external_id}"
        && $request->hasHeader('Authorization', 'Bearer sk_test_PHASE_64_SECRET'));

    expect($result->pac_status)->toBe('valid')
        ->and($result->last_pac_sync_at)->not->toBeNull()
        ->and($result->pac_response['livemode'])->toBeFalse()
        ->and($result->pac_last_error)->toBeNull()
        ->and($result->pac_reconciliation_required)->toBeFalse();
});

test('la llamada HTTP ocurre fuera de la transacción y la persistencia se relee después', function () {
    $invoice = phase64Invoice();
    $transactionLevelDuringHttp = null;
    $baselineTransactionLevel = DB::transactionLevel();

    Http::fake(function () use ($invoice, &$transactionLevelDuringHttp) {
        $transactionLevelDuringHttp = DB::transactionLevel();

        return Http::response([
            'id' => $invoice->pac_external_id,
            'livemode' => false,
            'status' => 'valid',
            'uuid' => $invoice->cfdi_uuid,
            'cancellation_status' => 'none',
        ], 200);
    });

    app(ReconcileInvoiceWithPacService::class)->reconcile($invoice);

    expect($transactionLevelDuringHttp)->toBe($baselineTransactionLevel)
        ->and($invoice->fresh()->last_pac_sync_at)->not->toBeNull();
});

test('livemode true es rechazado y nunca persiste la respuesta LIVE', function () {
    $invoice = phase64Invoice();
    $oldResponse = ['safe' => 'existing'];
    $invoice->forceFill(['pac_response' => $oldResponse])->save();
    fakePhase64Retrieve($invoice, ['livemode' => true]);

    expect(fn () => app(ReconcileInvoiceWithPacService::class)->reconcile($invoice->fresh()))
        ->toThrow(PacUnexpectedEnvironmentException::class);

    $fresh = $invoice->fresh();
    expect($fresh->pac_response)->toBe($oldResponse)
        ->and($fresh->pac_reconciliation_required)->toBeTrue();
});

test('id remoto diferente falla, marca reconciliación y conserva identidad local', function () {
    $invoice = phase64Invoice();
    fakePhase64Retrieve($invoice, ['id' => 'inv_wrong_resource']);

    expect(fn () => app(ReconcileInvoiceWithPacService::class)->reconcile($invoice))
        ->toThrow(PacUnexpectedResponseException::class, 'id remoto distinto');

    expect($invoice->fresh()->pac_external_id)->toBe($invoice->pac_external_id)
        ->and($invoice->fresh()->pac_reconciliation_required)->toBeTrue();
});

test('UUID mismatch ignora mayúsculas pero rechaza otro UUID sin sobrescribir el local', function () {
    $invoice = phase64Invoice();
    fakePhase64Retrieve($invoice, ['uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']);

    expect(fn () => app(ReconcileInvoiceWithPacService::class)->reconcile($invoice))
        ->toThrow(PacUnexpectedResponseException::class, 'UUID remoto no coincide');

    expect($invoice->fresh()->cfdi_uuid)->toBe($invoice->cfdi_uuid)
        ->and($invoice->fresh()->pac_reconciliation_required)->toBeTrue();
});

test('valid recupera UUID y stamp de forma controlada cuando localmente faltan', function () {
    $invoice = phase64Invoice();
    $invoice->forceFill([
        'cfdi_uuid' => null,
        'stamped_at' => null,
        'cfdi_artifacts_status' => null,
        'pac_reconciliation_required' => true,
    ])->save();
    $uuid = '12345678-1234-1234-1234-123456789abc';
    fakePhase64Retrieve($invoice->fresh(), ['uuid' => $uuid]);

    $result = app(ReconcileInvoiceWithPacService::class)->reconcile($invoice->fresh());

    expect($result->cfdi_uuid)->toBe($uuid)
        ->and($result->stamped_at)->not->toBeNull()
        ->and($result->pac_issue_status)->toBe('succeeded')
        ->and($result->pac_reconciliation_required)->toBeTrue(); // falta descarga separada de artifacts
});

test('valid sin UUID se rechaza y no destruye metadata fiscal existente', function () {
    $invoice = phase64Invoice();
    fakePhase64Retrieve($invoice, ['uuid' => null]);

    expect(fn () => app(ReconcileInvoiceWithPacService::class)->reconcile($invoice))
        ->toThrow(PacUnexpectedResponseException::class, 'status valid sin UUID');

    expect($invoice->fresh()->cfdi_uuid)->toBe($invoice->cfdi_uuid)
        ->and($invoice->fresh()->pac_status)->toBe('valid')
        ->and($invoice->fresh()->pac_reconciliation_required)->toBeTrue();
});

test('pending actualiza metadata PAC pero conserva UUID, stamp y status interno', function () {
    $invoice = phase64Invoice();
    fakePhase64Retrieve($invoice, ['status' => 'pending', 'uuid' => null, 'stamp' => null]);

    $result = app(ReconcileInvoiceWithPacService::class)->reconcile($invoice);

    expect($result->pac_status)->toBe('pending')
        ->and($result->cfdi_uuid)->toBe($invoice->cfdi_uuid)
        ->and($result->stamped_at->equalTo($invoice->stamped_at))->toBeTrue()
        ->and($result->status)->toBe(InvoiceStatus::Issued)
        ->and($result->pac_reconciliation_required)->toBeTrue();
});

test('draft con CFDI local es inconsistente y estado desconocido es fail-safe', function (string $remoteStatus) {
    $invoice = phase64Invoice();
    fakePhase64Retrieve($invoice, ['status' => $remoteStatus, 'uuid' => null, 'stamp' => null]);

    $result = app(ReconcileInvoiceWithPacService::class)->reconcile($invoice);

    expect($result->pac_status)->toBe($remoteStatus)
        ->and($result->cfdi_uuid)->toBe($invoice->cfdi_uuid)
        ->and($result->status)->toBe(InvoiceStatus::Issued)
        ->and($result->pac_reconciliation_required)->toBeTrue();
})->with(['draft', 'future_status']);

test('canceled sincroniza cancellation_status sin cancelar el workflow interno', function () {
    $invoice = phase64Invoice();
    fakePhase64Retrieve($invoice, [
        'status' => 'canceled',
        'cancellation_status' => 'accepted',
    ]);

    $result = app(ReconcileInvoiceWithPacService::class)->reconcile($invoice);

    expect($result->pac_status)->toBe('canceled')
        ->and($result->cancellation_status)->toBe('accepted')
        ->and($result->status)->toBe(InvoiceStatus::Issued)
        ->and($result->cancelled_at)->toBeNull()
        ->and($result->pac_reconciliation_required)->toBeFalse();
});

test('no modifica snapshots, totals, folio, company_id ni items y solo hace GET de retrieve', function () {
    $invoice = phase64Invoice();
    $item = InvoiceItem::factory()->create([
        'company_id' => $invoice->company_id,
        'invoice_id' => $invoice->id,
        'description' => 'Concepto congelado',
    ]);
    $before = $invoice->only([
        'company_id', 'folio', 'client_name', 'client_rfc', 'subtotal',
        'discount_total', 'tax_total', 'total', 'currency', 'sale_id', 'created_by',
    ]);
    $itemBefore = $item->fresh()->toArray();
    fakePhase64Retrieve($invoice, ['cancellation_status' => 'rejected']);

    app(ReconcileInvoiceWithPacService::class)->reconcile($invoice);

    expect($invoice->fresh()->only(array_keys($before)))->toBe($before)
        ->and($item->fresh()->toArray())->toBe($itemBefore);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && str_ends_with($request->url(), "/invoices/{$invoice->pac_external_id}"));
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/xml')
        || str_ends_with($request->url(), '/pdf')
        || str_ends_with($request->url(), '/stamp')
        || $request->method() !== 'GET');
});

test('idempotencia x3 conserva identidad y datos comerciales; solo renueva metadata de sync', function () {
    $invoice = phase64Invoice();
    fakePhase64Retrieve($invoice);
    $identity = $invoice->only(['company_id', 'folio', 'cfdi_uuid', 'total', 'currency']);

    $result = $invoice;
    foreach (range(1, 3) as $_) {
        $result = app(ReconcileInvoiceWithPacService::class)->reconcile($result);
    }

    Http::assertSentCount(3);
    expect($result->only(array_keys($identity)))->toBe($identity)
        ->and(Invoice::whereKey($invoice->id)->count())->toBe(1);
});

test('error PAC no corrompe metadata válida existente y los logs no incluyen API key ni respuesta', function () {
    Log::spy();
    $invoice = phase64Invoice();
    $oldSync = now()->subDay()->startOfSecond();
    $oldResponse = ['known' => 'good'];
    $invoice->forceFill(['last_pac_sync_at' => $oldSync, 'pac_response' => $oldResponse])->save();
    Http::fake(['*' => Http::response(['message' => 'Error remoto'], 500)]);

    $result = app(ReconcileInvoiceWithPacService::class)->reconcile($invoice->fresh());

    expect($result->cfdi_uuid)->toBe($invoice->cfdi_uuid)
        ->and($result->pac_status)->toBe('valid')
        ->and($result->pac_response)->toBe($oldResponse)
        ->and($result->last_pac_sync_at->equalTo($oldSync))->toBeTrue()
        ->and($result->pac_reconciliation_required)->toBeTrue();

    Log::shouldHaveReceived('info')->withArgs(function (string $event, array $context) {
        $serialized = json_encode($context);

        expect($serialized)->not->toContain('sk_test_PHASE_64_SECRET')
            ->and($context)->not->toHaveKey('pac_response');

        return true;
    });
});

test('InvoiceWorkflow delega la reconciliación sin duplicar lógica', function () {
    $invoice = phase64Invoice();
    fakePhase64Retrieve($invoice, ['cancellation_status' => 'expired']);

    $result = app(InvoiceWorkflow::class)->reconcileWithPac($invoice);

    expect($result->cancellation_status)->toBe('expired');
    Http::assertSentCount(1);
});
