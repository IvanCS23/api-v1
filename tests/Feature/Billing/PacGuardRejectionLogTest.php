<?php

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Exceptions\Billing\PacConfigurationException;
use App\Exceptions\Billing\PacOrganizationMismatchException;
use App\Exceptions\Billing\PacValidationException;
use App\Jobs\Billing\DownloadInvoiceArtifactsJob;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Support\Billing\PacGuardRejectionLog;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fase 6.29: cada rechazo del guard PAC queda en laravel.log como
 * billing.pac.guard_rejected (código interno, empresa, factura, acción y
 * usuario), nunca con llaves ni el mensaje de la excepción.
 */
beforeEach(function () {
    facturapiOrganizationsFromConfigKey();
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_PHASE_629_SECRET',
        'billing.pac_mode' => 'test',
    ]);
    Http::preventStrayRequests();
    Http::fake();
});

/** @return array{User, Invoice} */
function phase629GuardInvoice(array $attributes = []): array
{
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Admin]);
    $invoice = Invoice::factory()->create(array_merge([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Ready,
        'subtotal' => 100,
        'tax_total' => 0,
        'total' => 100,
    ], $attributes));
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id, 'subtotal' => 100, 'total' => 100]);
    app(CurrentTenant::class)->set($company->id);

    return [$user, $invoice->fresh()];
}

test('operations/issue rechazada por el guard deja el código, la empresa, la factura, la acción y el usuario', function () {
    config(['services.facturapi.test_key' => 'sk_live_PHASE_629_SECRET']);
    [$user, $invoice] = phase629GuardInvoice();
    app(CurrentTenant::class)->clear();
    Log::spy();

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/operations/issue", ['confirm' => true])
        ->assertStatus(503)
        ->assertJsonPath('code', 'PAC_CONFIGURATION_INVALID');

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []) use ($invoice, $user): bool {
        return $message === 'billing.pac.guard_rejected'
            && $context === [
                'code' => 'pac_key_mode_mismatch',
                'exception' => 'PacConfigurationException',
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'action' => 'issueBusiness',
                'user_id' => $user->id,
            ];
    })->once();
});

test('el registro nunca incluye la llave, el mensaje ni las organizaciones', function () {
    Log::spy();

    PacGuardRejectionLog::record(new PacOrganizationMismatchException(5, 'org_ajena_123', 'org_propia_456'), ['company_id' => 1, 'invoice_id' => 5]);
    PacGuardRejectionLog::record(new PacConfigurationException('pac_key_mode_mismatch', 'llave sk_live_FILTRADA'), ['company_id' => 1]);

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []): bool {
        $serialized = json_encode($context);

        return $message === 'billing.pac.guard_rejected'
            && ! str_contains($serialized, 'sk_')
            && ! str_contains($serialized, 'org_ajena_123')
            && ! str_contains($serialized, 'org_propia_456')
            && ! str_contains($serialized, 'FILTRADA');
    })->twice();
});

test('un error que no es del guard no se registra como rechazo del guard', function () {
    Log::spy();

    PacGuardRejectionLog::record(new PacValidationException('rechazo', 400, 'invalid'), ['company_id' => 1]);
    PacGuardRejectionLog::record(new RuntimeException('otro'), ['company_id' => 1]);

    Log::shouldNotHaveReceived('warning');
});

test('la descarga automática de XML/PDF rechazada por el guard también se registra', function () {
    config(['services.facturapi.test_key' => 'sk_live_PHASE_629_SECRET']);
    [, $invoice] = phase629GuardInvoice(['status' => InvoiceStatus::Issued]);
    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_guard_'.$invoice->id,
        'cfdi_uuid' => 'DDDDDDDD-1111-4222-8333-444444444444',
        'pac_status' => 'valid',
        'pac_issue_status' => 'succeeded',
        'stamped_at' => now(),
    ])->save();
    Log::spy();

    $job = (new DownloadInvoiceArtifactsJob((int) $invoice->company_id, (int) $invoice->id))->withFakeQueueInteractions();

    try {
        app()->call([$job, 'handle']);
    } catch (Throwable) {
        // El job puede relanzar para reintentar; lo que importa es el registro.
    }

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => $message === 'billing.pac.guard_rejected'
        && $context['code'] === 'pac_key_mode_mismatch'
        && $context['invoice_id'] === $invoice->id
        && $context['action'] === 'artifacts_auto_download')->once();
});
