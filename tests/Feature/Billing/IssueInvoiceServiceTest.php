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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Núcleo de IssueInvoiceService (Fase 6.2.1): éxito, guardas de estado/
 * tenant/idempotencia, evento tras commit, logging, y el límite de
 * transacción alrededor de la llamada HTTP. La idempotencia determinista,
 * la reserva `pending`, la concurrencia y la clasificación de fallos
 * definitivos/ambiguos tienen sus propios archivos (ver
 * IssueInvoiceIdempotencyTest, IssueInvoiceConcurrencyTest,
 * IssueInvoiceFailureHandlingTest) para mantener cada uno enfocado.
 */
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
    \App\Models\InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);

    app(CurrentTenant::class)->set($company->id);

    return $invoice->fresh(['items']);
}

test('emisión exitosa: persiste todos los campos PAC y de control de reserva, y retorna la Invoice actualizada', function () {
    $invoice = issuedInvoiceForIssueServiceTest();

    Http::fake(['*' => Http::response([
        'id' => 'inv_success_1',
        'status' => 'valid',
        'uuid' => 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE',
        'stamp' => ['date' => '2026-07-28T12:00:00Z'],
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
        ->and($updated->pac_idempotency_key)->toBe("erp-invoice:{$invoice->company_id}:{$invoice->id}:v1")
        ->and($updated->pac_issue_status)->toBe('succeeded')
        ->and($updated->pac_issue_attempts)->toBe(1)
        ->and($updated->pac_issue_started_at)->toBeInstanceOf(\Carbon\CarbonImmutable::class)
        ->and($updated->pac_reconciliation_required)->toBeFalse();

    $fresh = $invoice->fresh();
    expect($fresh->pac_external_id)->toBe('inv_success_1')
        ->and($fresh->status)->toBe(InvoiceStatus::Issued);
});

test('InvoiceCannotBeIssuedException: no se puede emitir una factura que no está en estado Issued, y no se llama al PAC ni se reserva nada', function (InvoiceStatus $status) {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => $status]);
    \App\Models\InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);
    app(CurrentTenant::class)->set($company->id);

    Http::fake();

    expect(fn () => app(IssueInvoiceService::class)->issue($invoice))
        ->toThrow(InvoiceCannotBeIssuedException::class);

    Http::assertNothingSent();

    $fresh = $invoice->fresh();
    expect($fresh->pac_external_id)->toBeNull()
        ->and($fresh->pac_issue_status)->toBeNull()
        ->and($fresh->pac_issue_attempts)->toBe(0);
})->with([InvoiceStatus::Draft, InvoiceStatus::Ready, InvoiceStatus::Cancelled]);

test('InvoiceAlreadyIssuedException: no se reenvía al PAC una factura que ya tiene cfdi_uuid o pac_external_id, y no se reserva nada', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    $invoice->forceFill(['pac_external_id' => 'inv_already'])->save();
    app(CurrentTenant::class)->set($company->id);

    Http::fake();

    expect(fn () => app(IssueInvoiceService::class)->issue($invoice))
        ->toThrow(InvoiceAlreadyIssuedException::class);

    Http::assertNothingSent();
    expect($invoice->fresh()->pac_issue_attempts)->toBe(0);
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

test('multi-tenant: sin tenant activo (CurrentTenant vacío), la emisión falla como si la Invoice no existiera', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);

    app(CurrentTenant::class)->clear();

    Http::fake();

    expect(fn () => app(IssueInvoiceService::class)->issue($invoice))
        ->toThrow(ModelNotFoundException::class);

    Http::assertNothingSent();
});

test('multi-tenant: la validación se hace contra CurrentTenant, no contra el company_id de la instancia recibida (defensa en profundidad si llega manipulada)', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $companyA->id, 'status' => InvoiceStatus::Issued]);
    \App\Models\InvoiceItem::factory()->create(['company_id' => $companyA->id, 'invoice_id' => $invoice->id]);

    $tampered = Invoice::withoutGlobalScope(CompanyScope::class)->find($invoice->id);
    $tampered->company_id = $companyB->id;

    app(CurrentTenant::class)->set($companyA->id);

    Http::fake(['*' => Http::response(['id' => 'inv_tenant_defense', 'status' => 'valid'], 200)]);

    $updated = app(IssueInvoiceService::class)->issue($tampered);

    expect($updated->company_id)->toBe($companyA->id)
        ->and($updated->pac_external_id)->toBe('inv_tenant_defense');
});

test('dispatch: InvoiceIssued se despacha una sola vez, únicamente después del commit, con la Invoice y el PacInvoiceResult', function () {
    // Fake acotado a InvoiceIssued: un Event::fake() sin argumentos
    // también reemplaza el dispatcher de eventos de modelo de Eloquent
    // (creating/created/...), lo que rompe el listener de Company que
    // autogenera `uuid` en creating().
    Event::fake([InvoiceIssued::class]);

    $invoice = issuedInvoiceForIssueServiceTest();

    Http::fake(['*' => Http::response(['id' => 'inv_evt', 'status' => 'valid'], 200)]);

    $updated = app(IssueInvoiceService::class)->issue($invoice);

    Event::assertDispatchedTimes(InvoiceIssued::class, 1);
    Event::assertDispatched(InvoiceIssued::class, function (InvoiceIssued $event) use ($updated) {
        return $event->invoice->is($updated)
            && $event->result->externalId === 'inv_evt'
            && $event->invoice->pac_external_id === 'inv_evt';
    });
});

test('dispatch: InvoiceIssued NO se despacha cuando la emisión falla (error de validación del PAC)', function () {
    Event::fake([InvoiceIssued::class]);

    $invoice = issuedInvoiceForIssueServiceTest();

    Http::fake(['*' => Http::response(['message' => 'RFC inválido', 'code' => 'invalid_rfc'], 422)]);

    try {
        app(IssueInvoiceService::class)->issue($invoice);
    } catch (\App\Exceptions\Billing\PacValidationException) {
        // esperado
    }

    Event::assertNotDispatched(InvoiceIssued::class);
});

test('dispatch: InvoiceIssued NO se despacha si la reserva bloquea la emisión (InvoiceCannotBeIssuedException)', function () {
    Event::fake([InvoiceIssued::class]);

    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Draft]);
    app(CurrentTenant::class)->set($company->id);

    try {
        app(IssueInvoiceService::class)->issue($invoice);
    } catch (InvoiceCannotBeIssuedException) {
        // esperado
    }

    Event::assertNotDispatched(InvoiceIssued::class);
});

test('logging: registra invoice_id, company_id, pac_provider, pac_issue_status, pac_external_id, intento y duración — nunca datos sensibles', function () {
    Log::spy();

    $invoice = issuedInvoiceForIssueServiceTest();

    Http::fake(['*' => Http::response(['id' => 'inv_log', 'status' => 'valid'], 200)]);

    $updated = app(IssueInvoiceService::class)->issue($invoice);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(function (string $message, array $context) use ($updated) {
            expect($context)->toHaveKeys(['invoice_id', 'company_id', 'pac_provider', 'pac_issue_status', 'pac_external_id', 'attempt', 'elapsed_ms', 'pac_error_code'])
                ->and($context['invoice_id'])->toBe($updated->id)
                ->and($context['company_id'])->toBe($updated->company_id)
                ->and($context['pac_provider'])->toBe('facturapi')
                ->and($context['pac_issue_status'])->toBe('succeeded')
                ->and($context['pac_external_id'])->toBe('inv_log')
                ->and($context['attempt'])->toBe(1)
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

test('la llamada HTTP al PAC ocurre fuera de cualquier transacción adicional (mismo nivel que antes de reservar)', function () {
    $invoice = issuedInvoiceForIssueServiceTest();

    // Baseline, no un 0 fijo: RefreshDatabase envuelve cada prueba en su
    // propia transacción externa, así que el nivel nunca es realmente 0
    // dentro de una prueba — lo relevante es que la llamada HTTP no abra
    // ninguna transacción adicional sobre ese baseline.
    $baselineTransactionLevel = DB::transactionLevel();

    $transactionLevelDuringCall = null;
    Http::fake(function () use (&$transactionLevelDuringCall) {
        $transactionLevelDuringCall = DB::transactionLevel();

        return Http::response(['id' => 'inv_tx_check', 'status' => 'valid'], 200);
    });

    app(IssueInvoiceService::class)->issue($invoice);

    expect($transactionLevelDuringCall)->toBe($baselineTransactionLevel);
});
