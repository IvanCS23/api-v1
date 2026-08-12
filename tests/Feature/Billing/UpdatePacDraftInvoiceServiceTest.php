<?php

use App\Contracts\Billing\PacProvider;
use App\Data\Billing\PacInvoiceDraftResult;
use App\Data\Billing\PacInvoiceRequest;
use App\Enums\InvoicePacEventType;
use App\Enums\InvoiceStatus;
use App\Exceptions\Billing\PacUnexpectedEnvironmentException;
use App\Exceptions\Billing\PacUnexpectedResponseException;
use App\Exceptions\InvoiceIssuanceInProgressException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Scopes\CompanyScope;
use App\Services\Billing\UpdatePacDraftInvoiceService;
use App\Support\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * UpdatePacDraftInvoiceService (Fase 6.2.7): actualiza (PUT) el MISMO
 * borrador ya existente en Facturapi TEST con el snapshot fiscal
 * corregido — nunca crea un draft nuevo, nunca timbra. Motivado por un
 * draft real creado ANTES de la corrección de tax_included/sku, que
 * conserva el payload viejo hasta actualizarse explícitamente.
 */
beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_UPDATE_DRAFT_SERVICE',
    ]);

    if (method_exists(Http::class, 'preventStrayRequests')) {
        Http::preventStrayRequests();
    }
});

function draftedInvoiceForUpdateTest(Company $company, array $overrides = []): Invoice
{
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Issued,
        'subtotal' => 100,
        'discount_total' => 0,
        'tax_total' => 16,
        'total' => 116,
    ]);
    $product = Product::factory()->create(['company_id' => $company->id]);
    InvoiceItem::factory()->create([
        'company_id' => $company->id,
        'invoice_id' => $invoice->id,
        'product_id' => $product->id,
        'unit_price' => 100,
        'tax_code' => '002',
        'tax_rate_value' => 0.16,
        'tax_type' => 'traslado',
        'tax_factor_type' => 'tasa',
    ]);
    $invoice->forceFill(array_merge([
        'pac_provider' => 'facturapi',
        'pac_draft_external_id' => 'inv_draft_update_'.$invoice->id,
        'pac_draft_idempotency_key' => "erp-invoice-draft:{$company->id}:{$invoice->id}:v1",
        'pac_draft_status' => 'draft',
        'pac_draft_ready_to_stamp' => false,
    ], $overrides))->save();

    // InvoiceItem también está protegido por CompanyScope — sin tenant
    // activo, `fresh(['items'])` cargaría la relación vacía (0 filas),
    // no null (fresh() en la propia Invoice sí bypassa scopes, pero la
    // relación eager-loaded es una query aparte contra InvoiceItem).
    app(CurrentTenant::class)->set($company->id);

    return $invoice->fresh(['items']);
}

function fakeUpdatedDraftBody(Invoice $invoice, array $overrides = []): array
{
    return array_merge([
        'id' => $invoice->pac_draft_external_id,
        'status' => 'draft',
        'livemode' => false,
        'is_ready_to_stamp' => true,
        'total' => 116,
    ], $overrides);
}

// ==================== CONTRACT (FacturapiProvider::updateDraftInvoice) ====================

test('updateDraftInvoice existe en el contrato PacProvider', function () {
    expect(method_exists(PacProvider::class, 'updateDraftInvoice'))->toBeTrue();
});

test('updateDraftInvoice llama PUT /invoices/{id} (el mismo id recibido), nunca POST /invoices', function () {
    Http::fake(['*' => Http::response(['id' => 'inv_contract_upd', 'status' => 'draft', 'livemode' => false, 'is_ready_to_stamp' => true], 200)]);

    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);
    app(CurrentTenant::class)->set($company->id);

    app(PacProvider::class)->updateDraftInvoice('inv_contract_upd', new PacInvoiceRequest(
        invoice: $invoice->fresh(['items']),
        idempotencyKey: 'erp-invoice-draft:1:1:v1',
        externalId: 'company-1-invoice-1-draft',
    ));

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_ends_with($request->url(), '/invoices/inv_contract_upd'));
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

test('el payload de updateDraftInvoice incluye status=draft y tax_included=false, igual que el resto del mapper corregido', function () {
    Http::fake(['*' => Http::response(['id' => 'inv_contract_payload', 'status' => 'draft', 'livemode' => false, 'is_ready_to_stamp' => true], 200)]);

    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id, 'tax_code' => '002', 'tax_rate_value' => 0.16]);
    app(CurrentTenant::class)->set($company->id);

    app(PacProvider::class)->updateDraftInvoice('inv_contract_payload', new PacInvoiceRequest(
        invoice: $invoice->fresh(['items']),
        idempotencyKey: 'erp-invoice-draft:1:1:v1',
        externalId: 'company-1-invoice-1-draft',
    ));

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $body['status'] === 'draft'
            && $body['items'][0]['product']['tax_included'] === false;
    });
});

test('devuelve PacInvoiceDraftResult, incluyendo total cuando Facturapi lo trae', function () {
    Http::fake(['*' => Http::response(['id' => 'inv_total', 'status' => 'draft', 'livemode' => false, 'is_ready_to_stamp' => true, 'total' => 116], 200)]);

    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);
    app(CurrentTenant::class)->set($company->id);

    $result = app(PacProvider::class)->updateDraftInvoice('inv_total', new PacInvoiceRequest(
        invoice: $invoice->fresh(['items']),
        idempotencyKey: 'k',
        externalId: 'e',
    ));

    expect($result)->toBeInstanceOf(PacInvoiceDraftResult::class)
        ->and($result->externalId)->toBe('inv_total')
        ->and($result->total)->toBe(116.0);
});

test('si la respuesta confirma un id remoto distinto del solicitado, lanza PacUnexpectedResponseException sin devolver el resultado', function () {
    Http::fake(['*' => Http::response(['id' => 'inv_otro_id', 'status' => 'draft', 'livemode' => false, 'is_ready_to_stamp' => true], 200)]);

    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);
    app(CurrentTenant::class)->set($company->id);

    expect(fn () => app(PacProvider::class)->updateDraftInvoice('inv_solicitado', new PacInvoiceRequest(
        invoice: $invoice->fresh(['items']),
        idempotencyKey: 'k',
        externalId: 'e',
    )))->toThrow(PacUnexpectedResponseException::class);
});

test('livemode=true bloquea con PacUnexpectedEnvironmentException', function () {
    Http::fake(['*' => Http::response(['id' => 'inv_live', 'status' => 'draft', 'livemode' => true, 'is_ready_to_stamp' => true], 200)]);

    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);
    app(CurrentTenant::class)->set($company->id);

    expect(fn () => app(PacProvider::class)->updateDraftInvoice('inv_live', new PacInvoiceRequest(
        invoice: $invoice->fresh(['items']),
        idempotencyKey: 'k',
        externalId: 'e',
    )))->toThrow(PacUnexpectedEnvironmentException::class);
});

// ==================== SERVICE: PRECONDICIONES / TENANT ====================

test('sin pac_draft_external_id: falla localmente sin llamar al PAC', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    app(CurrentTenant::class)->set($company->id);

    Http::fake();

    expect(fn () => app(UpdatePacDraftInvoiceService::class)->update($invoice))
        ->toThrow(RuntimeException::class);

    Http::assertNothingSent();
});

test('multi-tenant: no se puede actualizar el borrador de una Invoice de otra empresa', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $foreignInvoice = draftedInvoiceForUpdateTest($companyB);

    app(CurrentTenant::class)->set($companyA->id);

    Http::fake();

    expect(fn () => app(UpdatePacDraftInvoiceService::class)->update($foreignInvoice))
        ->toThrow(ModelNotFoundException::class);

    Http::assertNothingSent();
});

// ==================== SERVICE: SINCRONIZACIÓN / ESTADOS ====================

test('sincroniza primero (GET) y luego actualiza (PUT) cuando el remoto sigue en draft', function () {
    $company = Company::factory()->create();
    $invoice = draftedInvoiceForUpdateTest($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake([
        "*/invoices/{$invoice->pac_draft_external_id}" => Http::response(fakeUpdatedDraftBody($invoice, ['is_ready_to_stamp' => false]), 200),
    ]);
    // El PUT y el GET comparten la misma URL — Laravel's fake por patrón
    // responde la misma fake tanto al GET (sync) como al PUT (update).

    $updated = app(UpdatePacDraftInvoiceService::class)->update($invoice);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->method() === 'GET');
    Http::assertSent(fn ($request) => $request->method() === 'PUT');

    expect($updated->pac_draft_external_id)->toBe($invoice->pac_draft_external_id)
        ->and($updated->pac_draft_status)->toBe('draft');
});

test('si el remoto ya está "valid" tras sincronizar, reconcilia en vez de actualizar — nunca envía PUT', function () {
    $company = Company::factory()->create();
    $invoice = draftedInvoiceForUpdateTest($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake(['*' => Http::response([
        'id' => $invoice->pac_draft_external_id,
        'status' => 'valid',
        'livemode' => false,
        'uuid' => 'AAAAAAAA-1111-2222-3333-444444444444',
        'stamp' => ['date' => '2026-08-07T12:00:00Z'],
    ], 200)]);

    $result = app(UpdatePacDraftInvoiceService::class)->update($invoice);

    Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
    expect($result->cfdi_uuid)->toBe('AAAAAAAA-1111-2222-3333-444444444444');
});

test('si el remoto sigue "pending" tras sincronizar, bloquea con InvoiceIssuanceInProgressException — nunca envía PUT', function () {
    $company = Company::factory()->create();
    $invoice = draftedInvoiceForUpdateTest($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake(['*' => Http::response([
        'id' => $invoice->pac_draft_external_id,
        'status' => 'pending',
        'livemode' => false,
    ], 200)]);

    expect(fn () => app(UpdatePacDraftInvoiceService::class)->update($invoice))
        ->toThrow(InvoiceIssuanceInProgressException::class);

    Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
});

test('si el remoto está "canceled" tras sincronizar, no intenta actualizar: RuntimeException explícito', function () {
    $company = Company::factory()->create();
    $invoice = draftedInvoiceForUpdateTest($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake(['*' => Http::response([
        'id' => $invoice->pac_draft_external_id,
        'status' => 'canceled',
        'livemode' => false,
    ], 200)]);

    expect(fn () => app(UpdatePacDraftInvoiceService::class)->update($invoice))
        ->toThrow(RuntimeException::class);

    Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
});

// ==================== SERVICE: PERSISTENCIA ====================

test('persiste pac_draft_status/ready_to_stamp/last_sync_at/response tras un update exitoso; nunca toca cfdi_uuid/pac_external_id/stamped_at/pac_issue_status', function () {
    $company = Company::factory()->create();
    $invoice = draftedInvoiceForUpdateTest($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake(['*' => Http::response(fakeUpdatedDraftBody($invoice, ['is_ready_to_stamp' => true, 'total' => 116]), 200)]);

    $updated = app(UpdatePacDraftInvoiceService::class)->update($invoice);

    expect($updated->pac_draft_status)->toBe('draft')
        ->and($updated->pac_draft_ready_to_stamp)->toBeTrue()
        ->and($updated->pac_draft_last_sync_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($updated->pac_draft_response)->toBeArray()
        // conservados sin cambios:
        ->and($updated->pac_draft_external_id)->toBe($invoice->pac_draft_external_id)
        ->and($updated->pac_draft_idempotency_key)->toBe($invoice->pac_draft_idempotency_key)
        // nunca tocados:
        ->and($updated->cfdi_uuid)->toBeNull()
        ->and($updated->pac_external_id)->toBeNull()
        ->and($updated->stamped_at)->toBeNull()
        ->and($updated->pac_issue_status)->toBeNull()
        ->and($updated->pacEvents()->pluck('event_type')->all())->toBe([
            InvoicePacEventType::DraftSynced,
            InvoicePacEventType::DraftUpdated,
        ]);
});

test('consistencia de totales: registra el total remoto (116) y confirma que coincide con subtotal/tax_total/total locales', function () {
    Log::spy();

    $company = Company::factory()->create();
    $invoice = draftedInvoiceForUpdateTest($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake(['*' => Http::response(fakeUpdatedDraftBody($invoice, ['total' => 116]), 200)]);

    $updated = app(UpdatePacDraftInvoiceService::class)->update($invoice);

    expect((float) $updated->subtotal)->toBe(100.0)
        ->and((float) $updated->tax_total)->toBe(16.0)
        ->and((float) $updated->total)->toBe(116.0);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context) => $message === 'billing.invoice.pac_draft_updated'
            && $context['remote_total'] === 116.0
            && $context['local_total'] === 116.0
            && $context['totals_match'] === true)
        ->once();

    Log::shouldNotHaveReceived('warning');
});

test('si Facturapi devuelve un total remoto distinto, lo reporta (log warning) y NO recalcula el total local para forzar coincidencia', function () {
    Log::spy();

    $company = Company::factory()->create();
    $invoice = draftedInvoiceForUpdateTest($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake(['*' => Http::response(fakeUpdatedDraftBody($invoice, ['total' => 100]), 200)]);

    $updated = app(UpdatePacDraftInvoiceService::class)->update($invoice);

    // El total LOCAL nunca se toca — sigue siendo 116, la discrepancia
    // no se "resuelve" recalculando nada.
    expect((float) $updated->total)->toBe(116.0);

    Log::shouldHaveReceived('warning')
        ->with('billing.invoice.pac_draft_total_mismatch', Mockery::on(function (array $context) {
            return $context['local_total'] === 116.0 && $context['remote_total'] === 100.0;
        }))
        ->once();
});

// ==================== IDEMPOTENCIA ====================

test('actualizar el mismo draft varias veces conserva el mismo pac_draft_external_id y la misma idempotency key — nunca crea un recurso nuevo', function () {
    $company = Company::factory()->create();
    $invoice = draftedInvoiceForUpdateTest($company);
    $originalExternalId = $invoice->pac_draft_external_id;
    $originalIdempotencyKey = $invoice->pac_draft_idempotency_key;
    app(CurrentTenant::class)->set($company->id);

    Http::fake(['*' => Http::response(fakeUpdatedDraftBody($invoice), 200)]);

    $first = app(UpdatePacDraftInvoiceService::class)->update($invoice);
    $second = app(UpdatePacDraftInvoiceService::class)->update($first);
    $third = app(UpdatePacDraftInvoiceService::class)->update($second);

    expect($first->pac_draft_external_id)->toBe($originalExternalId)
        ->and($second->pac_draft_external_id)->toBe($originalExternalId)
        ->and($third->pac_draft_external_id)->toBe($originalExternalId)
        ->and($third->pac_draft_idempotency_key)->toBe($originalIdempotencyKey);

    // 3 llamadas = 3 GET (sync) + 3 PUT (update), nunca un POST /invoices.
    Http::assertSentCount(6);
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');

    expect(Invoice::withoutGlobalScope(CompanyScope::class)->where('pac_draft_external_id', $originalExternalId)->count())->toBe(1);
});

test('el payload PUT usa exactamente el mismo external_id/idempotency_key en cada llamada', function () {
    $company = Company::factory()->create();
    $invoice = draftedInvoiceForUpdateTest($company);
    app(CurrentTenant::class)->set($company->id);

    Http::fake(['*' => Http::response(fakeUpdatedDraftBody($invoice), 200)]);

    app(UpdatePacDraftInvoiceService::class)->update($invoice);
    app(UpdatePacDraftInvoiceService::class)->update($invoice->fresh());

    $bodies = collect(Http::recorded(fn ($request) => $request->method() === 'PUT'))
        ->map(fn ($pair) => $pair[0]->data())
        ->values();

    expect($bodies)->toHaveCount(2)
        ->and($bodies[0]['idempotency_key'])->toBe($bodies[1]['idempotency_key'])
        ->and($bodies[0]['external_id'])->toBe($bodies[1]['external_id']);
});

// ==================== SNAPSHOTS: NUNCA CONSULTA PRODUCT/CLIENT/TAXRATE VIVOS ====================

test('el payload de update usa exclusivamente el snapshot: modificar el Product vivo después de crear el draft no afecta el PUT', function () {
    $company = Company::factory()->create();
    $invoice = draftedInvoiceForUpdateTest($company);
    $item = $invoice->items->first();
    app(CurrentTenant::class)->set($company->id);

    Product::withoutGlobalScope(CompanyScope::class)->whereKey($item->product_id)->update(['no_identificacion' => 'SKU-CAMBIADO-DESPUES']);

    Http::fake(['*' => Http::response(fakeUpdatedDraftBody($invoice), 200)]);

    DB::enableQueryLog();
    app(UpdatePacDraftInvoiceService::class)->update($invoice);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect(collect($queries)->contains(fn ($q) => str_contains(strtolower($q['query']), 'from `products`') || str_contains(strtolower($q['query']), 'from "products"')))
        ->toBeFalse();

    Http::assertSent(function ($request) {
        if ($request->method() !== 'PUT') {
            return false;
        }

        $raw = json_encode($request->data());

        return ! str_contains($raw, 'SKU-CAMBIADO-DESPUES');
    });
});

// ==================== COMANDO ====================

test('comando: prohibido en production', function () {
    $invoice = draftedInvoiceForUpdateTest(Company::factory()->create());

    app()->instance('env', 'production');

    $this->artisan('billing:facturapi-test-draft-update', ['invoice' => $invoice->id])
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('comando: requiere FACTURAPI_TEST_KEY', function () {
    config(['services.facturapi.test_key' => null]);
    $invoice = draftedInvoiceForUpdateTest(Company::factory()->create());

    $this->artisan('billing:facturapi-test-draft-update', ['invoice' => $invoice->id])
        ->expectsOutputToContain('FACTURAPI_TEST_KEY no está configurada')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('comando: invoice inexistente falla con un mensaje claro', function () {
    $this->artisan('billing:facturapi-test-draft-update', ['invoice' => 999999])
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('comando: sin borrador remoto, falla localmente sin HTTP', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);

    $this->artisan('billing:facturapi-test-draft-update', ['invoice' => $invoice->id])
        ->expectsOutputToContain('no tiene un borrador remoto registrado')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('comando: advierte explícitamente antes de pedir confirmación', function () {
    $invoice = draftedInvoiceForUpdateTest(Company::factory()->create());

    $this->artisan('billing:facturapi-test-draft-update', ['invoice' => $invoice->id])
        ->expectsOutputToContain('ACTUALIZARÁ el draft existente en Facturapi TEST')
        ->expectsOutputToContain('No creará otro recurso y no timbrará')
        ->expectsConfirmation('¿Confirmas que quieres ACTUALIZAR el borrador de la Invoice ['.$invoice->id.'] en Facturapi TEST?', 'no')
        ->assertExitCode(0);
});

test('comando: confirmación rechazada no hace HTTP ni modifica la Invoice', function () {
    $invoice = draftedInvoiceForUpdateTest(Company::factory()->create());
    $before = $invoice->pac_draft_response;

    $this->artisan('billing:facturapi-test-draft-update', ['invoice' => $invoice->id])
        ->expectsConfirmation('¿Confirmas que quieres ACTUALIZAR el borrador de la Invoice ['.$invoice->id.'] en Facturapi TEST?', 'no')
        ->expectsOutputToContain('Cancelado. No se realizó ninguna llamada HTTP.')
        ->assertExitCode(0);

    Http::assertNothingSent();
    expect($invoice->fresh()->pac_draft_response)->toBe($before);
});

test('comando: con confirmación aceptada, actualiza el borrador y muestra el resumen sanitizado, incluyendo el total remoto', function () {
    $invoice = draftedInvoiceForUpdateTest(Company::factory()->create());

    Http::fake(['*' => Http::response(fakeUpdatedDraftBody($invoice, ['total' => 116]), 200)]);

    $this->artisan('billing:facturapi-test-draft-update', ['invoice' => $invoice->id])
        ->expectsConfirmation('¿Confirmas que quieres ACTUALIZAR el borrador de la Invoice ['.$invoice->id.'] en Facturapi TEST?', 'yes')
        ->expectsOutputToContain('Borrador actualizado correctamente')
        ->assertExitCode(0);

    $fresh = $invoice->fresh();
    expect($fresh->pac_draft_status)->toBe('draft')
        ->and($fresh->pac_draft_ready_to_stamp)->toBeTrue();
});

test('comando: si el total remoto no coincide, muestra una advertencia visible sin ocultarlo', function () {
    $invoice = draftedInvoiceForUpdateTest(Company::factory()->create());

    Http::fake(['*' => Http::response(fakeUpdatedDraftBody($invoice, ['total' => 100]), 200)]);

    $this->artisan('billing:facturapi-test-draft-update', ['invoice' => $invoice->id])
        ->expectsConfirmation('¿Confirmas que quieres ACTUALIZAR el borrador de la Invoice ['.$invoice->id.'] en Facturapi TEST?', 'yes')
        ->expectsOutputToContain('El total remoto no coincide con el total local')
        ->assertExitCode(0);
});

test('comando: la salida nunca contiene la API key, el RFC, el domicilio, Authorization, ni el payload/pac_draft_response completo', function () {
    $company = Company::factory()->create();
    $invoice = draftedInvoiceForUpdateTest($company);
    $invoice->forceFill([
        'client_rfc' => 'COMANDOUPDATESECRETO01A',
        'client_calle' => 'Calle Confidencial Del Update 321',
    ])->save();

    Http::fake(['*' => Http::response(fakeUpdatedDraftBody($invoice), 200)]);

    $this->artisan('billing:facturapi-test-draft-update', ['invoice' => $invoice->id])
        ->expectsConfirmation('¿Confirmas que quieres ACTUALIZAR el borrador de la Invoice ['.$invoice->id.'] en Facturapi TEST?', 'yes')
        ->doesntExpectOutputToContain('COMANDOUPDATESECRETO01A')
        ->doesntExpectOutputToContain('Calle Confidencial Del Update')
        ->doesntExpectOutputToContain('sk_test_UPDATE_DRAFT_SERVICE')
        ->doesntExpectOutputToContain('Bearer')
        ->doesntExpectOutputToContain('Authorization')
        ->assertExitCode(0);
});
