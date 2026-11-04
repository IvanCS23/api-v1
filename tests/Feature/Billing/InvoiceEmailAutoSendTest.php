<?php

use App\Enums\InvoicePacEventType;
use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Jobs\Billing\SendInvoiceEmailJob;
use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoicePacEvent;
use App\Models\User;
use App\Services\Billing\InvoicePacAuditService;
use App\Services\Billing\StampPacDraftInvoiceService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.30: envío automático del CFDI por correo tras el timbrado,
 * configurable por empresa. Un fallo del correo nunca afecta la emisión.
 */
beforeEach(function () {
    facturapiOrganizationsFromConfigKey();
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_PHASE_630_AUTO',
        'billing.email.company_daily_limit' => 100,
    ]);
    Http::preventStrayRequests();
});

function phase630AutoInvoice(bool $auto = true, ?string $clientEmail = 'cliente@example.com'): Invoice
{
    $company = Company::factory()->create(['auto_email_invoices' => $auto]);
    $client = Client::factory()->create(['company_id' => $company->id, 'email' => $clientEmail ?? 'temporal@example.com']);

    if ($clientEmail === null) {
        $client->forceFill(['email' => 'sin-correo'])->saveQuietly();
    }

    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'client_id' => $client->id, 'status' => InvoiceStatus::Issued]);
    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_draft_external_id' => 'inv_autosend_'.$invoice->id,
        'pac_draft_idempotency_key' => "erp-invoice-draft:{$company->id}:{$invoice->id}:v1",
        'pac_draft_status' => 'draft',
        'pac_draft_ready_to_stamp' => true,
    ])->save();
    app(CurrentTenant::class)->set($company->id);

    return $invoice->fresh();
}

/** Simula sync del borrador, /stamp y, opcionalmente, el correo. */
function phase630FakeStamp(Invoice $invoice, mixed $email = ['ok' => true]): void
{
    $id = $invoice->pac_draft_external_id;

    Http::fake([
        "*/invoices/{$id}/email" => is_callable($email) ? $email : Http::response($email, 200),
        "*/invoices/{$id}/stamp" => Http::response([
            'id' => $id, 'status' => 'valid', 'livemode' => false,
            'uuid' => sprintf('FFFFFFFF-1111-4222-8333-%012d', $invoice->id), 'stamp' => ['date' => '2026-11-03T12:00:00'],
        ]),
        "*/invoices/{$id}" => Http::response(['id' => $id, 'status' => 'draft', 'livemode' => false, 'is_ready_to_stamp' => true]),
    ]);
}

function phase630RunAutoJob(Invoice $invoice): void
{
    $job = (new SendInvoiceEmailJob((int) $invoice->company_id, (int) $invoice->id))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);
}

function phase630AutoEvents(Invoice $invoice): array
{
    return InvoicePacEvent::withoutGlobalScopes()->where('invoice_id', $invoice->id)
        ->where('event_type', 'like', 'email_%')->orderBy('id')->get()
        ->map(fn (InvoicePacEvent $event): array => [$event->event_type->value, $event->context['trigger'] ?? null, $event->context['reason'] ?? null])
        ->all();
}

test('la migración agrega companies.auto_email_invoices apagado por defecto', function () {
    expect(Schema::hasColumn('companies', 'auto_email_invoices'))->toBeTrue()
        ->and(Company::factory()->create()->fresh()->auto_email_invoices)->toBeFalse();
});

test('el job es único por factura, va en cola y se intenta una sola vez', function () {
    $job = new SendInvoiceEmailJob(1, 2);

    expect($job)->toBeInstanceOf(ShouldQueue::class)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->tries)->toBe(1)
        ->and($job->uniqueId())->toBe('1:2');
});

test('con el envío automático activado, el timbrado encola un envío', function () {
    Queue::fake();
    $invoice = phase630AutoInvoice();
    phase630FakeStamp($invoice);

    app(StampPacDraftInvoiceService::class)->stamp($invoice);

    Queue::assertPushed(SendInvoiceEmailJob::class, fn (SendInvoiceEmailJob $job): bool => $job->invoiceId === $invoice->id);
});

test('apagado, el timbrado no encola nada', function () {
    Queue::fake();
    $invoice = phase630AutoInvoice(auto: false);
    phase630FakeStamp($invoice);

    app(StampPacDraftInvoiceService::class)->stamp($invoice);

    Queue::assertNotPushed(SendInvoiceEmailJob::class);
});

test('el job envía a los destinatarios del cliente y lo registra como automático, una sola vez', function () {
    $invoice = phase630AutoInvoice();
    phase630FakeStamp($invoice);
    Queue::fake();
    app(StampPacDraftInvoiceService::class)->stamp($invoice);

    phase630RunAutoJob($invoice);
    phase630RunAutoJob($invoice);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/email') && $request->data() === ['email' => ['cliente@example.com']]);
    expect(collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/email')))->toHaveCount(1)
        ->and(phase630AutoEvents($invoice))->toBe([['email_sent', 'auto', null]]);
});

test('sin correo válido del cliente queda omitido, sin contactar a Facturapi', function () {
    $invoice = phase630AutoInvoice(clientEmail: null);
    phase630FakeStamp($invoice);
    Queue::fake();
    app(StampPacDraftInvoiceService::class)->stamp($invoice);

    phase630RunAutoJob($invoice);

    expect(phase630AutoEvents($invoice))->toBe([['email_skipped', 'auto', 'no_recipients']]);
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/email'));
});

test('con el tope diario agotado queda omitido', function () {
    config(['billing.email.company_daily_limit' => 1]);
    $invoice = phase630AutoInvoice();
    phase630FakeStamp($invoice);
    Queue::fake();
    $stamped = app(StampPacDraftInvoiceService::class)->stamp($invoice);
    app(InvoicePacAuditService::class)->append($stamped, InvoicePacEventType::EmailSent, ['trigger' => 'manual']);

    phase630RunAutoJob($invoice);

    expect(phase630AutoEvents($invoice))->toBe([['email_sent', 'manual', null], ['email_skipped', 'auto', 'daily_limit']]);
});

test('un timeout del correo queda incierto, el job no falla y la emisión sigue confirmada', function () {
    $invoice = phase630AutoInvoice();
    phase630FakeStamp($invoice, fn () => throw new ConnectionException('timeout'));
    Queue::fake();
    $stamped = app(StampPacDraftInvoiceService::class)->stamp($invoice);

    phase630RunAutoJob($invoice);

    expect($stamped->pac_issue_status)->toBe('succeeded')
        ->and($invoice->fresh()->cfdi_uuid)->not->toBeNull()
        ->and(phase630AutoEvents($invoice))->toBe([['email_uncertain', 'auto', 'ConnectionException']]);
});

test('con la cola sync, un fallo del correo no rompe el timbrado', function () {
    $invoice = phase630AutoInvoice();
    phase630FakeStamp($invoice, ['ok' => false]);

    $stamped = app(StampPacDraftInvoiceService::class)->stamp($invoice);

    expect($stamped->pac_issue_status)->toBe('succeeded')
        ->and(phase630AutoEvents($invoice))->toBe([['email_failed', 'auto', 'email_not_sent']]);
});

test('configuración: Admin la cambia, Contador sólo la consulta, Ventas no la ve', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Admin]);
    $accountant = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Accountant]);
    $sales = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Sales]);

    $this->actingAs($accountant, 'api')->getJson('/api/company/email-settings')->assertOk()
        ->assertJson(['auto_email_invoices' => false, 'pac_mode' => 'test', 'daily_limit' => 100, 'sent_last_24h' => 0, 'can_edit' => false]);
    $this->actingAs($accountant, 'api')->putJson('/api/company/email-settings', ['auto_email_invoices' => true])->assertForbidden();
    $this->actingAs($sales, 'api')->getJson('/api/company/email-settings')->assertForbidden();

    $this->actingAs($admin, 'api')->putJson('/api/company/email-settings', ['auto_email_invoices' => true])->assertOk()
        ->assertJson(['auto_email_invoices' => true, 'can_edit' => true]);
    $this->actingAs($admin, 'api')->putJson('/api/company/email-settings', ['auto_email_invoices' => 'quizá'])->assertUnprocessable();

    expect($company->fresh()->auto_email_invoices)->toBeTrue();
});
