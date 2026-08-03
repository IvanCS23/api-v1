<?php

use App\Contracts\Billing\PacProvider;
use App\Data\Billing\PacInvoiceRequest;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Billing\IssueInvoiceService;
use App\Services\Billing\ReconcileInvoiceWithPacService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cobertura de seguridad transversal (Fase 6.2.2): ninguna llamada
 * real sale a Internet (Http::preventStrayRequests()), la API key nunca
 * aparece en excepciones, y ni el payload fiscal completo ni la
 * respuesta cruda del PAC llegan a los logs.
 */
beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_MUY_SECRETA_SECURITY_112233',
    ]);

    if (method_exists(Http::class, 'preventStrayRequests')) {
        Http::preventStrayRequests();
    }
});

test('con Http::preventStrayRequests() activo, una emisión exitosa completa no realiza ninguna solicitud real (todo queda cubierto por el fake)', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);
    app(CurrentTenant::class)->set($company->id);

    Http::fake(['*' => Http::response(['id' => 'inv_security', 'status' => 'valid'], 200)]);

    // Si alguna solicitud no coincidiera con el fake, preventStrayRequests()
    // haría que Laravel lance una excepción en vez de golpear la red real.
    $updated = app(IssueInvoiceService::class)->issue($invoice->fresh(['items']));

    expect($updated->pac_external_id)->toBe('inv_security');
});

test('con Http::preventStrayRequests() activo, una reconciliación completa (retrieveInvoice + findInvoiceByExternalId) no realiza ninguna solicitud real', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_idempotency_key' => "erp-invoice:{$company->id}:{$invoice->id}:v1",
        'pac_issue_status' => 'reconciliation_required',
        'pac_reconciliation_required' => true,
    ])->save();
    app(CurrentTenant::class)->set($company->id);

    Http::fake(['*' => Http::response(['page' => 1, 'total_pages' => 1, 'total_results' => 0, 'data' => []], 200)]);

    $result = app(ReconcileInvoiceWithPacService::class)->reconcile($invoice->fresh());

    expect($result->pac_reconciliation_required)->toBeTrue();
});

test('la API key nunca aparece en ninguna excepción lanzada por FacturapiProvider (createInvoice, retrieveInvoice, findInvoiceByExternalId)', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);
    app(CurrentTenant::class)->set($company->id);

    Http::fake(['*' => Http::response(['message' => 'No autorizado', 'code' => 'unauthorized'], 401)]);

    $secret = 'sk_test_MUY_SECRETA_SECURITY_112233';
    $request = new PacInvoiceRequest(
        invoice: $invoice->fresh(['items']),
        idempotencyKey: "erp-invoice:{$company->id}:{$invoice->id}:v1",
        externalId: "company-{$company->id}-invoice-{$invoice->id}",
    );

    foreach ([
        fn () => app(PacProvider::class)->createInvoice($request),
        fn () => app(PacProvider::class)->retrieveInvoice('inv_x'),
        fn () => app(PacProvider::class)->findInvoiceByExternalId('company-1-invoice-1'),
    ] as $call) {
        try {
            $call();
            test()->fail('Se esperaba una excepción PAC');
        } catch (\App\Exceptions\Billing\PacException $e) {
            expect($e->getMessage())->not->toContain($secret)
                ->and((string) $e->pacCode)->not->toContain($secret)
                ->and((string) $e)->not->toContain($secret);
        }
    }
});

test('el log de una emisión exitosa nunca incluye el payload fiscal completo, RFC, ni domicilio', function () {
    Log::spy();

    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Issued,
        'client_rfc' => 'SECRETRFC010101AAA',
        'client_calle' => 'Avenida Secreta Confidencial 123',
    ]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);
    app(CurrentTenant::class)->set($company->id);

    Http::fake(['*' => Http::response(['id' => 'inv_log_security', 'status' => 'valid'], 200)]);

    app(IssueInvoiceService::class)->issue($invoice->fresh(['items']));

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) {
        $serialized = json_encode($context);

        expect($context)->not->toHaveKey('pac_response')
            ->and($context)->not->toHaveKey('client_rfc')
            ->and($context)->not->toHaveKey('client_calle')
            ->and($serialized)->not->toContain('SECRETRFC010101AAA')
            ->and($serialized)->not->toContain('Avenida Secreta Confidencial');

        return true;
    });
});

test('el log de una reconciliación ambigua nunca incluye los ids de las facturas en conflicto ni la respuesta cruda del PAC', function () {
    Log::spy();

    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_idempotency_key' => "erp-invoice:{$company->id}:{$invoice->id}:v1",
        'pac_issue_status' => 'reconciliation_required',
        'pac_reconciliation_required' => true,
    ])->save();
    app(CurrentTenant::class)->set($company->id);

    Http::fake(['*' => Http::response([
        'page' => 1, 'total_pages' => 1, 'total_results' => 2,
        'data' => [
            ['id' => 'inv_conflict_secret_1', 'status' => 'valid'],
            ['id' => 'inv_conflict_secret_2', 'status' => 'valid'],
        ],
    ], 200)]);

    app(ReconcileInvoiceWithPacService::class)->reconcile($invoice->fresh());

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) {
        $serialized = json_encode($context);

        expect($serialized)->not->toContain('inv_conflict_secret_1')
            ->and($serialized)->not->toContain('inv_conflict_secret_2');

        return true;
    });
});
