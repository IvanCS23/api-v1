<?php

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Infrastructure\Billing\Facturapi\FacturapiOrganizationSnapshot;
use App\Models\Company;
use App\Models\FacturapiOrganization;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\InvoicePacReadinessService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Support\Facades\Http;

/**
 * Fase 6.31 (8): en Live la emisión exige CSD vigente, la organización
 * lista para producción y el correo de contacto igual al support_email de
 * Facturapi (el "Responder" de los correos al cliente). En Test el correo
 * sólo se avisa.
 */
beforeEach(function () {
    config(['services.facturapi.base_url' => 'https://example-pac.test/v2', 'billing.pac_mode' => 'test']);
    Http::preventStrayRequests();
});

/** @return array{Company, Invoice, User} */
function phase631IssuerInvoice(string $environment): array
{
    $company = Company::factory()->withFacturapiOrganization('sk_test_PHASE_631_IR_TEST_KEY_1')->create([
        'rfc' => 'XIA190128J61', 'legal_name' => 'XENON INDUSTRIAL ARTICLES', 'fiscal_regime' => '601', 'postal_code' => '06010',
    ]);
    FacturapiOrganization::withoutGlobalScopes()->where('company_id', $company->id)->update(['legal_tax_id' => 'XIA190128J61']);
    $user = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Admin]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Ready, 'pac_environment' => null]);
    phase631ProductionReadyOrganization($company);

    if ($environment === 'live') {
        phase631MakeLive($company->id);
    }

    app(CurrentTenant::class)->set($company->id);

    return [$company, $invoice->fresh(), $user];
}

function phase631IssuerCodes(Invoice $invoice): array
{
    return collect(app(InvoicePacReadinessService::class)->evaluate($invoice)['errors'])
        ->pluck('code')
        ->filter(fn (string $code): bool => str_starts_with($code, 'COMPANY_'))
        ->values()
        ->all();
}

test('una organización Live lista para producción no reporta nada del emisor', function () {
    [, $invoice] = phase631IssuerInvoice('live');

    expect(phase631IssuerCodes($invoice))->toBe([]);
});

test('en Live cada requisito del emisor bloquea con su código', function (Closure $break, string $code) {
    [$company, $invoice] = phase631IssuerInvoice('live');
    $break($company, FacturapiOrganization::withoutGlobalScopes()->where('company_id', $company->id)->firstOrFail());

    expect(phase631IssuerCodes($invoice->fresh()))->toBe([$code]);
})->with([
    'sin CSD' => [fn (Company $c, FacturapiOrganization $o) => $o->forceFill(['has_certificate' => false])->save(), 'COMPANY_CSD_MISSING'],
    'CSD vencido' => [fn (Company $c, FacturapiOrganization $o) => $o->forceFill(['certificate_expires_at' => now()->subDay()])->save(), 'COMPANY_CSD_EXPIRED'],
    'no lista para producción' => [fn (Company $c, FacturapiOrganization $o) => $o->forceFill(['is_production_ready' => false])->save(), 'COMPANY_NOT_PRODUCTION_READY'],
    'con pasos pendientes' => [fn (Company $c, FacturapiOrganization $o) => $o->forceFill(['pending_steps' => [['type' => 'manifiesto', 'description' => 'Firma']]])->save(), 'COMPANY_NOT_PRODUCTION_READY'],
    'sin correo de contacto' => [fn (Company $c, FacturapiOrganization $o) => $c->forceFill(['email' => null])->save(), 'COMPANY_CONTACT_EMAIL_MISSING'],
    'support_email distinto' => [fn (Company $c, FacturapiOrganization $o) => $o->forceFill(['legal_support_email' => 'otro@example.com'])->save(), 'COMPANY_SUPPORT_EMAIL_MISMATCH'],
    'support_email vacío en Facturapi' => [fn (Company $c, FacturapiOrganization $o) => $o->forceFill(['legal_support_email' => null])->save(), 'COMPANY_SUPPORT_EMAIL_MISMATCH'],
]);

test('el support_email se compara sin distinguir mayúsculas', function () {
    [$company, $invoice] = phase631IssuerInvoice('live');
    FacturapiOrganization::withoutGlobalScopes()->where('company_id', $company->id)->update(['legal_support_email' => strtoupper((string) $company->email)]);

    expect(phase631IssuerCodes($invoice->fresh()))->toBe([]);
});

test('en Test el correo de contacto no bloquea y se avisa en GET /billing', function (?string $email, ?string $supportEmail, ?string $issue) {
    [$company, $invoice, $user] = phase631IssuerInvoice('test');
    $company->forceFill(['email' => $email])->save();
    FacturapiOrganization::withoutGlobalScopes()->where('company_id', $company->id)->update(['legal_support_email' => $supportEmail]);
    app(CurrentTenant::class)->clear();

    $this->actingAs($user, 'api')
        ->getJson("/api/invoices/{$invoice->id}/billing")
        ->assertOk()
        ->assertJsonPath('issuer.contact_email_issue', $issue);

    app(CurrentTenant::class)->set($company->id);
    expect(phase631IssuerCodes($invoice->fresh()))->toBe([]);
})->with([
    'sincronizado' => ['contacto@example.com', 'contacto@example.com', null],
    'sin correo' => [null, 'contacto@example.com', 'missing'],
    'distinto' => ['contacto@example.com', 'heredado@example.com', 'mismatch'],
]);

test('el snapshot de la organización guarda legal.support_email', function () {
    $snapshot = FacturapiOrganizationSnapshot::fromResponse(['id' => 'org_1', 'legal' => ['support_email' => ' contacto@example.com ']]);

    expect($snapshot->toAttributes()['legal_support_email'])->toBe('contacto@example.com');
});

test('Datos fiscales publica el ambiente y el aviso del correo de contacto', function (string $environment, ?string $supportEmail, ?string $issue) {
    [$company, , $user] = phase631IssuerInvoice($environment);

    // 'sincronizado': phase631ProductionReadyOrganization ya dejó el mismo correo en ambos lados.
    if ($supportEmail !== 'sincronizado') {
        FacturapiOrganization::withoutGlobalScopes()->where('company_id', $company->id)->update(['legal_support_email' => $supportEmail]);
    }

    app(CurrentTenant::class)->clear();

    $this->actingAs($user, 'api')
        ->getJson('/api/company/fiscal')
        ->assertOk()
        ->assertJsonPath('organization.pac_environment', $environment)
        ->assertJsonPath('organization.contact_email_issue', $issue);
})->with([
    'test sincronizado' => ['test', 'sincronizado', null],
    'live sincronizado' => ['live', 'sincronizado', null],
    'live distinto' => ['live', 'otro@example.com', 'mismatch'],
    'test sin support_email' => ['test', null, 'mismatch'],
]);
