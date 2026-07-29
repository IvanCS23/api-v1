<?php

use App\Enums\InvoiceStatus;
use App\Events\Billing\InvoiceIssued;
use App\Exceptions\InvoiceAlreadyIssuedException;
use App\Exceptions\InvoiceCannotBeIssuedException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Services\Billing\IssueInvoiceService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_ISSUE_SERVICE',
    ]);
});

function issuedInvoiceForIssueServiceTest(?Company $company = null): Invoice
{
    $company ??= Company::factory()->create();

    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Issued,
    ]);

    app(CurrentTenant::class)->set($company->id);

    return $invoice->fresh();
}

test('emisión exitosa: persiste todos los campos PAC y retorna la Invoice actualizada', function () {
    $invoice = issuedInvoiceForIssueServiceTest();

    Http::fake(['*' => Http::response([
        'id' => 'inv_success_1',
        'status' => 'valid',
        'uuid' => 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE',
        'stamped_at' => '2026-07-28T12:00:00Z',
    ], 200)]);

    $updated = app(IssueInvoiceService::class)->issue($invoice);

    expect($updated->pac_provider)->toBe('facturapi')
        ->and($updated->pac_external_id)->toBe('inv_success_1')
        ->and($updated->cfdi_uuid)->toBe('AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE')
        ->and($updated->pac_status)->toBe('valid')
        ->and($updated->stamped_at)->toBeInstanceOf(\Carbon\CarbonImmutable::class)
        ->and($updated->last_pac_sync_at)->toBeInstanceOf(\Carbon\CarbonImmutable::class)
        ->and($updated->pac_last_error)->toBeNull()
        ->and($updated->pac_response)->toBeArray()
        ->and($updated->pac_response['id'])->toBe('inv_success_1');

    $fresh = $invoice->fresh();
    expect($fresh->pac_external_id)->toBe('inv_success_1')
        ->and($fresh->cfdi_uuid)->toBe('AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE')
        ->and($fresh->pac_status)->toBe('valid')
        ->and($fresh->status)->toBe(InvoiceStatus::Issued);
});

test('dispatch: se emite InvoiceIssued con la Invoice actualizada y el PacInvoiceResult', function () {
    // Fake acotado únicamente a InvoiceIssued: un Event::fake() sin
    // argumentos también reemplaza el dispatcher de eventos de modelo de
    // Eloquent (creating/created/...), lo que rompe el listener de
    // Company que autogenera `uuid` en creating().
    Event::fake([InvoiceIssued::class]);

    $invoice = issuedInvoiceForIssueServiceTest();

    Http::fake(['*' => Http::response(['id' => 'inv_evt', 'status' => 'valid'], 200)]);

    $updated = app(IssueInvoiceService::class)->issue($invoice);

    Event::assertDispatched(InvoiceIssued::class, function (InvoiceIssued $event) use ($updated) {
        return $event->invoice->is($updated)
            && $event->result->externalId === 'inv_evt'
            && $event->invoice->pac_external_id === 'inv_evt';
    });
});

test('logging: registra solo invoice_id, company_id, pac_provider, pac_external_id y tiempo de respuesta, nunca datos sensibles', function () {
    Log::spy();

    $invoice = issuedInvoiceForIssueServiceTest();

    Http::fake(['*' => Http::response(['id' => 'inv_log', 'status' => 'valid'], 200)]);

    $updated = app(IssueInvoiceService::class)->issue($invoice);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(function (string $message, array $context) use ($updated) {
            expect($context)->toHaveKeys(['invoice_id', 'company_id', 'pac_provider', 'pac_external_id', 'elapsed_ms'])
                ->and($context['invoice_id'])->toBe($updated->id)
                ->and($context['company_id'])->toBe($updated->company_id)
                ->and($context['pac_provider'])->toBe('facturapi')
                ->and($context['pac_external_id'])->toBe('inv_log')
                ->and($context)->not->toHaveKey('pac_response')
                ->and($context)->not->toHaveKey('cfdi_uuid')
                ->and($context)->not->toHaveKey('client_rfc');

            $serialized = json_encode($context);
            expect($serialized)->not->toContain('sk_test_ISSUE_SERVICE')
                ->and($serialized)->not->toContain('Bearer')
                ->and($serialized)->not->toContain('Authorization');

            return true;
        });
});

test('InvoiceCannotBeIssuedException: no se puede emitir una factura que no está en estado Issued, y no se llama al PAC', function (InvoiceStatus $status) {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => $status]);
    app(CurrentTenant::class)->set($company->id);

    Http::fake();

    expect(fn () => app(IssueInvoiceService::class)->issue($invoice))
        ->toThrow(InvoiceCannotBeIssuedException::class);

    Http::assertNothingSent();
    expect($invoice->fresh()->pac_external_id)->toBeNull();
})->with([InvoiceStatus::Draft, InvoiceStatus::Ready, InvoiceStatus::Cancelled]);

test('InvoiceAlreadyIssuedException: no se reenvía al PAC una factura que ya tiene cfdi_uuid o pac_external_id', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    $invoice->forceFill(['pac_external_id' => 'inv_already'])->save();
    app(CurrentTenant::class)->set($company->id);

    Http::fake();

    expect(fn () => app(IssueInvoiceService::class)->issue($invoice))
        ->toThrow(InvoiceAlreadyIssuedException::class);

    Http::assertNothingSent();
});

test('idempotencia bajo concurrencia: si la fila ya fue marcada como emitida justo antes del lock, se lanza InvoiceAlreadyIssuedException y no se sobrescribe el resultado ya persistido', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    app(CurrentTenant::class)->set($company->id);

    Http::fake(function () use ($invoice) {
        // Simula que otro proceso concurrente completó el timbrado justo
        // después de nuestra validación inicial y antes de adquirir el lock.
        Invoice::withoutGlobalScope(CompanyScope::class)->whereKey($invoice->id)->update([
            'pac_provider' => 'facturapi',
            'pac_external_id' => 'inv_concurrent_winner',
            'cfdi_uuid' => 'CCCCCCCC-DDDD-EEEE-FFFF-000000000000',
        ]);

        return Http::response(['id' => 'inv_race_loser', 'status' => 'valid'], 200);
    });

    expect(fn () => app(IssueInvoiceService::class)->issue($invoice))
        ->toThrow(InvoiceAlreadyIssuedException::class);

    expect($invoice->fresh()->pac_external_id)->toBe('inv_concurrent_winner');
});

test('multi-tenant: no se puede emitir una factura que realmente pertenece a otra empresa', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $foreignInvoice = Invoice::factory()->create(['company_id' => $companyB->id, 'status' => InvoiceStatus::Issued]);

    app(CurrentTenant::class)->set($companyA->id);

    Http::fake();

    expect(fn () => app(IssueInvoiceService::class)->issue($foreignInvoice))
        ->toThrow(ModelNotFoundException::class);

    Http::assertNothingSent();
});

test('multi-tenant: la validación se hace contra CurrentTenant, no contra el company_id de la instancia recibida (defensa en profundidad si llega manipulada)', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $companyA->id, 'status' => InvoiceStatus::Issued]);

    // Instancia en memoria con company_id manipulado a companyB — nunca
    // persistido. IssueInvoiceService nunca confía en este atributo: relee
    // por id, filtrando por el CurrentTenant activo (companyA), así que la
    // manipulación en memoria no tiene ningún efecto sobre la validación.
    $tampered = Invoice::withoutGlobalScope(CompanyScope::class)->find($invoice->id);
    $tampered->company_id = $companyB->id;

    app(CurrentTenant::class)->set($companyA->id);

    Http::fake(['*' => Http::response(['id' => 'inv_tenant_defense', 'status' => 'valid'], 200)]);

    $updated = app(IssueInvoiceService::class)->issue($tampered);

    expect($updated->company_id)->toBe($companyA->id)
        ->and($updated->pac_external_id)->toBe('inv_tenant_defense');
});

test('multi-tenant: sin tenant activo (CurrentTenant vacío), la emisión falla como si la Invoice no existiera', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);

    app(CurrentTenant::class)->clear();

    Http::fake();

    expect(fn () => app(IssueInvoiceService::class)->issue($invoice))
        ->toThrow(ModelNotFoundException::class);

    Http::assertNothingSent();
});

test('transacción: si la persistencia falla (violación de índice único), hace rollback completo y no deja campos PAC parciales', function () {
    $company = Company::factory()->create();
    app(CurrentTenant::class)->set($company->id);

    $existing = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    $existing->forceFill([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_duplicado',
    ])->save();

    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);

    // Misma company + mismo pac_provider + mismo pac_external_id que
    // $existing => viola erp_invoices_pac_provider_external_unique.
    Http::fake(['*' => Http::response([
        'id' => 'inv_duplicado',
        'status' => 'valid',
        'uuid' => 'BBBBBBBB-1111-2222-3333-444444444444',
    ], 200)]);

    expect(fn () => app(IssueInvoiceService::class)->issue($invoice))
        ->toThrow(QueryException::class);

    $fresh = $invoice->fresh();
    expect($fresh->pac_provider)->toBeNull()
        ->and($fresh->pac_external_id)->toBeNull()
        ->and($fresh->cfdi_uuid)->toBeNull()
        ->and($fresh->pac_status)->toBeNull()
        ->and($fresh->stamped_at)->toBeNull()
        ->and($fresh->last_pac_sync_at)->toBeNull();
});

test('la llamada HTTP al PAC ocurre fuera de la transacción de persistencia (no se abre transacción si el PAC nunca responde exitosamente)', function () {
    $invoice = issuedInvoiceForIssueServiceTest();

    Http::fake(['*' => Http::response(['message' => 'Error interno del PAC'], 500)]);

    $transactionLevelBefore = DB::transactionLevel();

    expect(fn () => app(IssueInvoiceService::class)->issue($invoice))
        ->toThrow(\App\Exceptions\Billing\PacUnavailableException::class);

    expect(DB::transactionLevel())->toBe($transactionLevelBefore);

    $fresh = $invoice->fresh();
    expect($fresh->pac_external_id)->toBeNull()
        ->and($fresh->pac_status)->toBeNull();
});
