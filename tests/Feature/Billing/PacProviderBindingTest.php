<?php

use App\Contracts\Billing\PacProvider;
use App\Contracts\Billing\PacProviderResolver;
use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Exceptions\Billing\PacConfigurationException;
use App\Infrastructure\Billing\Facturapi\FacturapiProvider;
use App\Infrastructure\Billing\Facturapi\FacturapiProviderResolver;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Billing\ReconcileInvoiceWithPacService;
use App\Support\Billing\PacCredentialGuard;
use App\Support\Billing\PacSecretRedactor;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Fase 6.27 (3/7): el provider se resuelve con la organización de la
 * empresa de cada factura; ya no hay un PacProvider global con la llave de
 * .env.
 */
beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v9',
        // La llave de .env sigue existiendo (vínculo inicial), pero nada en
        // tiempo de ejecución debe usarla.
        'services.facturapi.test_key' => 'sk_test_ENV_GLOBAL_MUST_NOT_BE_USED',
        'services.facturapi.timeout' => 42,
        'services.facturapi.connect_timeout' => 7,
        'billing.pac_mode' => 'test',
    ]);

    Http::preventStrayRequests();
});

function phase627ResolverInvoice(Company $company, array $attributes = []): Invoice
{
    $invoice = Invoice::factory()->create(array_merge([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Issued,
    ], $attributes));
    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_resolver_'.$invoice->id,
        'cfdi_uuid' => 'fced601d-c4f6-4ce7-8f05-f3d38de530f9',
        'pac_status' => 'valid',
        'pac_issue_status' => 'succeeded',
    ])->save();

    return $invoice->fresh();
}

function phase627ProviderProperty(FacturapiProvider $provider, string $property): mixed
{
    return (new ReflectionClass($provider))->getProperty($property)->getValue($provider);
}

test('el contenedor entrega el resolver de Facturapi y ya no un PacProvider global', function () {
    expect(app(PacProviderResolver::class))->toBeInstanceOf(FacturapiProviderResolver::class)
        ->and(app()->bound(PacProvider::class))->toBeFalse();
});

test('el provider de una empresa lleva su organización, su llave y la configuración de transporte', function () {
    $company = Company::factory()->withFacturapiOrganization('sk_test_COMPANY_A_KEY_1111', 'org_company_a')->create();
    app(CurrentTenant::class)->set($company->id);

    $provider = app(PacProviderResolver::class)->forCompany($company->id);

    expect($provider)->toBeInstanceOf(FacturapiProvider::class)
        ->and(phase627ProviderProperty($provider, 'apiKey'))->toBe('sk_test_COMPANY_A_KEY_1111')
        ->and(phase627ProviderProperty($provider, 'organizationId'))->toBe('org_company_a')
        ->and(phase627ProviderProperty($provider, 'organizationRequired'))->toBeTrue()
        ->and(phase627ProviderProperty($provider, 'baseUrl'))->toBe('https://example-pac.test/v9')
        ->and(phase627ProviderProperty($provider, 'timeout'))->toBe(42)
        ->and(phase627ProviderProperty($provider, 'connectTimeout'))->toBe(7)
        ->and(phase627ProviderProperty($provider, 'stampTimezone'))->toBe('America/Mexico_City');
});

test('cada factura se envía con la llave de su empresa, nunca con la de .env ni la de otra empresa', function () {
    Http::fake(['example-pac.test/v9/invoices/*' => fn (Request $request) => Http::response([
        'id' => basename(parse_url($request->url(), PHP_URL_PATH)),
        'status' => 'valid',
        'uuid' => 'fced601d-c4f6-4ce7-8f05-f3d38de530f9',
        'livemode' => false,
    ])]);
    $companyA = Company::factory()->withFacturapiOrganization('sk_test_COMPANY_A_KEY_1111')->create();
    $companyB = Company::factory()->withFacturapiOrganization('sk_test_COMPANY_B_KEY_2222')->create();
    $invoiceA = phase627ResolverInvoice($companyA);
    $invoiceB = phase627ResolverInvoice($companyB);

    app(CurrentTenant::class)->set($companyA->id);
    app(ReconcileInvoiceWithPacService::class)->reconcile($invoiceA);
    app(CurrentTenant::class)->set($companyB->id);
    app(ReconcileInvoiceWithPacService::class)->reconcile($invoiceB);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/invoices/'.$invoiceA->pac_external_id)
        && $request->hasHeader('Authorization', 'Bearer sk_test_COMPANY_A_KEY_1111'));
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/invoices/'.$invoiceB->pac_external_id)
        && $request->hasHeader('Authorization', 'Bearer sk_test_COMPANY_B_KEY_2222'));
    Http::assertNotSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer sk_test_ENV_GLOBAL_MUST_NOT_BE_USED'));
    Http::assertSentCount(2);
});

test('una empresa sin organización falla antes de cualquier HTTP aunque exista la llave de .env', function () {
    $company = Company::factory()->create();
    app(CurrentTenant::class)->set($company->id);

    $provider = app(PacProviderResolver::class)->forCompany($company->id);

    expect(fn () => $provider->retrieveInvoice('inv_1'))
        ->toThrow(PacConfigurationException::class, 'pac_organization_missing')
        ->and(fn () => $provider->downloadXml('inv_1'))
        ->toThrow(PacConfigurationException::class, 'pac_organization_missing');
    Http::assertNothingSent();
});

test('con el tenant de otra empresa la organización no se encuentra y falla cerrado', function () {
    $companyA = Company::factory()->withFacturapiOrganization('sk_test_COMPANY_A_KEY_1111')->create();
    $companyB = Company::factory()->withFacturapiOrganization('sk_test_COMPANY_B_KEY_2222')->create();
    app(CurrentTenant::class)->set($companyB->id);

    $provider = app(PacProviderResolver::class)->forCompany($companyA->id);

    expect(phase627ProviderProperty($provider, 'apiKey'))->toBeNull()
        ->and(fn () => $provider->retrieveInvoice('inv_1'))->toThrow(PacConfigurationException::class, 'pac_organization_missing');
    Http::assertNothingSent();
});

test('el guard por empresa conserva los motivos de la 6.19', function (?string $mode, ?string $key, ?string $reason) {
    config(['billing.pac_mode' => $mode]);
    $company = $key === 'sin organización'
        ? Company::factory()->create()
        : Company::factory()->withFacturapiOrganization($key)->create();
    app(CurrentTenant::class)->set($company->id);

    expect(PacCredentialGuard::forCompany($company->id)->violation())->toBe($reason);
})->with([
    'válida' => ['test', 'sk_test_VALID_COMPANY_KEY_0000', null],
    'sin organización' => ['test', 'sin organización', 'pac_organization_missing'],
    'organización sin llave' => ['test', null, 'pac_key_missing'],
    'llave live en modo test' => ['test', 'sk_live_COMPANY_KEY_0000', 'pac_key_mode_mismatch'],
    'modo live, antes que la organización' => ['live', 'sin organización', 'pac_mode_unsupported'],
]);

test('operations/issue de una empresa sin organización no transiciona el ERP ni contacta al PAC', function () {
    Http::fake();
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Admin]);
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Ready,
        'subtotal' => 100,
        'tax_total' => 0,
        'total' => 100,
    ]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id, 'subtotal' => 100, 'total' => 100]);

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/operations/issue", ['confirm' => true])
        ->assertStatus(503)
        ->assertJsonPath('code', 'PAC_CONFIGURATION_INVALID');

    expect(DB::table('invoices')->where('id', $invoice->id)->value('status'))->toBe('ready')
        ->and(DB::table('invoices')->where('id', $invoice->id)->value('pac_issue_status'))->toBeNull();
    Http::assertNothingSent();
});

test('los textos que se guardan o se registran tapan la llave de cualquier empresa, no sólo la de .env', function () {
    $redacted = PacSecretRedactor::redact('Authorization: Bearer sk_test_COMPANY_A_KEY_1111 | sk_live_COMPANY_B_9999 | {"k":"sk_user_ACCOUNT_KEY"}');

    expect($redacted)->not->toContain('COMPANY_A_KEY')
        ->and($redacted)->not->toContain('COMPANY_B')
        ->and($redacted)->not->toContain('ACCOUNT_KEY')
        ->and($redacted)->not->toContain('Bearer');
});
