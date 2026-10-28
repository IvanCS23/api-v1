<?php

use App\Contracts\Billing\PacProviderResolver;
use App\Enums\InvoicePacEventType;
use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Exceptions\Billing\PacOrganizationMismatchException;
use App\Exceptions\Billing\PacUnexpectedResponseException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\ReconcileInvoiceWithPacService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Fase 6.27 (4/7): identidad de organización por factura. Se guarda la
 * organización que devuelve el PAC y se verifica antes de operar.
 */
const PHASE_627_ID_ORG = 'org_current_6ad00a70';
const PHASE_627_ID_OTHER_ORG = 'org_previous_69e1341a';

beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_ENV_NOT_USED_AT_RUNTIME',
        'billing.pac_mode' => 'test',
    ]);

    Http::preventStrayRequests();
});

/** @return array{0: Company, 1: User} */
function phase627IdentityCompany(): array
{
    $company = Company::factory()->withFacturapiOrganization('sk_test_IDENTITY_COMPANY_KEY', PHASE_627_ID_ORG)->create();
    $user = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Admin]);

    return [$company, $user];
}

function phase627IssuedInvoice(Company $company, ?string $organization, array $extra = []): Invoice
{
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    $invoice->forceFill(array_merge([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_identity_'.$invoice->id,
        'cfdi_uuid' => fake()->uuid(),
        'pac_status' => 'valid',
        'cancellation_status' => 'none',
        'pac_issue_status' => 'succeeded',
        'pac_reconciliation_required' => false,
    ], $extra))->save();

    DB::table('invoices')->where('id', $invoice->id)->update(['pac_organization_id' => $organization]);

    return $invoice->fresh();
}

function phase627RemoteInvoice(Invoice $invoice, ?string $organization): array
{
    return array_filter([
        'id' => $invoice->pac_external_id,
        'organization' => $organization,
        'status' => 'valid',
        'uuid' => $invoice->cfdi_uuid,
        'livemode' => false,
        'cancellation_status' => 'none',
    ], fn (mixed $value): bool => $value !== null);
}

test('al guardar la respuesta del PAC la factura toma su organización', function () {
    [$company] = phase627IdentityCompany();
    $invoice = phase627IssuedInvoice($company, null);

    $invoice->forceFill(['pac_response' => ['id' => 'inv_x', 'organization' => PHASE_627_ID_ORG]])->save();
    expect($invoice->fresh()->pac_organization_id)->toBe(PHASE_627_ID_ORG);

    // Emisión y borrador en organizaciones distintas: no se elige ninguna.
    $conflict = phase627IssuedInvoice($company, null);
    $conflict->forceFill([
        'pac_response' => ['id' => 'inv_y', 'organization' => PHASE_627_ID_ORG],
        'pac_draft_response' => ['id' => 'inv_y', 'organization' => PHASE_627_ID_OTHER_ORG],
    ])->save();
    expect($conflict->fresh()->pac_organization_id)->toBeNull();
});

test('reconciliar guarda la organización que devuelve el PAC', function () {
    [$company] = phase627IdentityCompany();
    $invoice = phase627IssuedInvoice($company, null);
    Http::fake(['example-pac.test/v2/invoices/*' => Http::response(phase627RemoteInvoice($invoice, PHASE_627_ID_ORG))]);

    app(CurrentTenant::class)->set($company->id);
    app(ReconcileInvoiceWithPacService::class)->reconcile($invoice);

    expect($invoice->fresh()->pac_organization_id)->toBe(PHASE_627_ID_ORG);
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk_test_IDENTITY_COMPANY_KEY'));
});

test('una respuesta de otra organización se rechaza y no cambia la identidad de la factura', function () {
    [$company] = phase627IdentityCompany();
    $invoice = phase627IssuedInvoice($company, null);
    Http::fake(['example-pac.test/v2/invoices/*' => Http::response(phase627RemoteInvoice($invoice, PHASE_627_ID_OTHER_ORG))]);

    app(CurrentTenant::class)->set($company->id);

    expect(fn () => app(ReconcileInvoiceWithPacService::class)->reconcile($invoice, throwOnFailure: true))
        ->toThrow(PacUnexpectedResponseException::class, 'pertenece a la organización '.PHASE_627_ID_OTHER_ORG)
        ->and($invoice->fresh()->pac_organization_id)->toBeNull();
});

test('el provider de una factura de otra organización falla antes del HTTP; name() sigue respondiendo', function () {
    [$company] = phase627IdentityCompany();
    $invoice = phase627IssuedInvoice($company, PHASE_627_ID_OTHER_ORG);
    app(CurrentTenant::class)->set($company->id);

    $provider = app(PacProviderResolver::class)->forInvoice($invoice);

    expect($provider->name())->toBe('facturapi')
        ->and(fn () => $provider->downloadXml((string) $invoice->pac_external_id))
        ->toThrow(PacOrganizationMismatchException::class, 'se emitió en otra organización de Facturapi ('.PHASE_627_ID_OTHER_ORG.')')
        ->and(fn () => $provider->retrieveInvoice((string) $invoice->pac_external_id))
        ->toThrow(PacOrganizationMismatchException::class);
    Http::assertNothingSent();
});

test('acciones PAC sobre una factura de otra organización: 409 claro, sin HTTP y sin tocar la factura', function (string $endpoint, array $body) {
    [$company, $user] = phase627IdentityCompany();
    $invoice = phase627IssuedInvoice($company, PHASE_627_ID_OTHER_ORG);
    $before = DB::table('invoices')->where('id', $invoice->id)->first();

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/{$endpoint}", $body)
        ->assertStatus(409)
        ->assertJsonPath('code', 'PAC_ORGANIZATION_MISMATCH');

    expect((array) DB::table('invoices')->where('id', $invoice->id)->first())->toEqual((array) $before)
        ->and(DB::table('invoice_pac_events')->where('invoice_id', $invoice->id)->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'reconciliar' => ['pac/reconcile', []],
    'descargar XML/PDF' => ['pac/artifacts', []],
    'cancelar (PAC)' => ['pac/cancel', ['confirm' => true, 'motive' => '02']],
    'cancelar (operación de negocio)' => ['operations/cancel', ['confirm' => true, 'motive' => '02']],
]);

test('una factura archivada también se rechaza aunque la organización coincida', function () {
    [$company, $user] = phase627IdentityCompany();
    $invoice = phase627IssuedInvoice($company, PHASE_627_ID_ORG);
    DB::table('invoices')->where('id', $invoice->id)->update(['pac_organization_archived_at' => now()]);

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/pac/reconcile")
        ->assertStatus(409)
        ->assertJsonPath('code', 'PAC_ORGANIZATION_MISMATCH');

    Http::assertNothingSent();
});

test('billing expone el estado de organización y apaga las acciones de una factura inaccesible', function (?string $organization, ?string $status, bool $blocked) {
    [$company, $user] = phase627IdentityCompany();
    $invoice = phase627IssuedInvoice($company, $organization, ['cfdi_artifacts_status' => null]);

    $response = $this->actingAs($user, 'api')->getJson("/api/invoices/{$invoice->id}/billing")->assertOk();

    expect($response->json('pac.organization_status'))->toBe($status)
        ->and($response->json('actions.can_fetch_artifacts'))->toBe(! $blocked)
        ->and($response->json('actions.can_cancel'))->toBe(! $blocked)
        ->and($response->getContent())->not->toContain(PHASE_627_ID_ORG)
        ->and($response->getContent())->not->toContain('sk_test_IDENTITY_COMPANY_KEY');
})->with([
    'misma organización' => [PHASE_627_ID_ORG, 'current', false],
    'otra organización' => [PHASE_627_ID_OTHER_ORG, 'other', true],
    'sin organización guardada' => [null, 'unknown', false],
]);

test('una factura que nunca llegó al PAC no tiene estado de organización', function () {
    [$company, $user] = phase627IdentityCompany();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Ready]);

    $this->actingAs($user, 'api')->getJson("/api/invoices/{$invoice->id}/billing")
        ->assertOk()
        ->assertJsonPath('pac.organization_status', null);
});

test('backfill en dry-run reporta sin escribir; con --apply llena sólo la columna', function () {
    [$company] = phase627IdentityCompany();
    $current = phase627IssuedInvoice($company, null);
    $previous = phase627IssuedInvoice($company, null);
    $missing = phase627IssuedInvoice($company, null);
    $current->forceFill(['pac_response' => ['id' => 'a', 'organization' => PHASE_627_ID_ORG]])->saveQuietly();
    $previous->forceFill(['pac_draft_response' => ['id' => 'b', 'organization' => PHASE_627_ID_OTHER_ORG]])->saveQuietly();
    DB::table('invoices')->update(['pac_organization_id' => null, 'updated_at' => '2026-10-01 00:00:00']);

    expect(Artisan::call('billing:backfill-pac-organization'))->toBe(0);
    $dry = Artisan::output();

    expect($dry)->toContain('dry-run')
        ->and($dry)->toContain(PHASE_627_ID_OTHER_ORG)
        ->and($dry)->toContain('NO')
        ->and($dry)->toContain('(sin organización guardada)')
        ->and($dry)->toContain('Por llenar: 2')
        ->and(DB::table('invoices')->whereNotNull('pac_organization_id')->count())->toBe(0);

    expect(Artisan::call('billing:backfill-pac-organization', ['--apply' => true]))->toBe(0);

    expect(DB::table('invoices')->where('id', $current->id)->value('pac_organization_id'))->toBe(PHASE_627_ID_ORG)
        ->and(DB::table('invoices')->where('id', $previous->id)->value('pac_organization_id'))->toBe(PHASE_627_ID_OTHER_ORG)
        ->and(DB::table('invoices')->where('id', $missing->id)->value('pac_organization_id'))->toBeNull()
        ->and(DB::table('invoices')->where('updated_at', '<>', '2026-10-01 00:00:00')->count())->toBe(0);

    // Idempotente: una segunda pasada no encuentra nada por llenar.
    Artisan::call('billing:backfill-pac-organization', ['--apply' => true]);
    expect(Artisan::output())->toContain('Llenadas: 0');
});

test('backfill respeta --company y nunca sobrescribe una organización ya registrada', function () {
    [$companyA] = phase627IdentityCompany();
    $companyB = Company::factory()->create();
    $invoiceA = phase627IssuedInvoice($companyA, PHASE_627_ID_ORG);
    $invoiceB = phase627IssuedInvoice($companyB, null);
    $invoiceA->forceFill(['pac_response' => ['id' => 'a', 'organization' => PHASE_627_ID_OTHER_ORG]])->saveQuietly();
    $invoiceB->forceFill(['pac_response' => ['id' => 'b', 'organization' => 'org_company_b']])->saveQuietly();
    DB::table('invoices')->where('id', $invoiceB->id)->update(['pac_organization_id' => null]);

    Artisan::call('billing:backfill-pac-organization', ['--apply' => true, '--company' => [$companyA->id]]);

    expect(DB::table('invoices')->where('id', $invoiceA->id)->value('pac_organization_id'))->toBe(PHASE_627_ID_ORG)
        ->and(DB::table('invoices')->where('id', $invoiceB->id)->value('pac_organization_id'))->toBeNull();
});

test('el evento de cancelación nunca se registra para una factura de otra organización', function () {
    [$company, $user] = phase627IdentityCompany();
    $invoice = phase627IssuedInvoice($company, PHASE_627_ID_OTHER_ORG);

    $this->actingAs($user, 'api')->postJson("/api/invoices/{$invoice->id}/operations/cancel", ['confirm' => true, 'motive' => '02']);

    expect(DB::table('invoice_pac_events')->where('event_type', InvoicePacEventType::CancellationRequested->value)->count())->toBe(0)
        ->and(DB::table('invoices')->where('id', $invoice->id)->value('cancellation_status'))->toBe('none');
});
