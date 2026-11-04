<?php

use App\Enums\InvoicePacEventType;
use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoicePacEvent;
use App\Models\User;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fase 6.30: POST /api/invoices/{id}/operations/email — envío del CFDI por
 * correo con Facturapi. Admin, Contador y Ventas envían; sólo Admin y
 * Contador cambian los destinatarios.
 */
beforeEach(function () {
    facturapiOrganizationsFromConfigKey();
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_PHASE_630_SEND',
        'billing.email.company_daily_limit' => 100,
    ]);
    Http::preventStrayRequests();
});

/** @return array{Company, Invoice, Client} */
function phase630Invoice(array $fiscal = [], array $client = [], ?Company $company = null): array
{
    $company ??= Company::factory()->create();
    $attributes = array_merge([
        'company_id' => $company->id,
        'email' => 'principal@example.com',
        'email_optional_1' => 'contador@example.com',
        'email_optional_2' => null,
    ], $client);
    // El correo principal es único por empresa: reutiliza ese cliente si ya existe.
    $clientModel = Client::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('email', $attributes['email'])->first()
        ?? Client::factory()->create($attributes);
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'client_id' => $clientModel->id, 'status' => InvoiceStatus::Issued]);
    $invoice->forceFill(array_merge([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_mail_'.$invoice->id,
        'cfdi_uuid' => sprintf('EEEEEEEE-1111-4222-8333-%012d', $invoice->id),
        'pac_status' => 'valid',
        'pac_issue_status' => 'succeeded',
        'stamped_at' => now(),
    ], $fiscal))->save();

    return [$company, $invoice->fresh(), $clientModel];
}

function phase630User(Company $company, UserRole $role = UserRole::Admin): User
{
    return User::factory()->create(['company_id' => $company->id, 'role' => $role]);
}

function phase630FakeEmail(array $body = ['ok' => true], int $status = 200): void
{
    Http::fake(['*/invoices/*/email' => Http::response($body, $status)]);
}

function phase630Send(User $user, Invoice $invoice, array $payload = [])
{
    app(CurrentTenant::class)->clear();

    return test()->actingAs($user, 'api')->postJson("/api/invoices/{$invoice->id}/operations/email", ['confirm' => true, ...$payload]);
}

/** @return list<InvoicePacEvent> */
function phase630Events(Invoice $invoice, InvoicePacEventType $type): array
{
    return InvoicePacEvent::withoutGlobalScopes()->where('invoice_id', $invoice->id)->where('event_type', $type->value)->get()->all();
}

test('sin destinatarios envía a los del cliente en nuestra base, no al correo que tenga Facturapi', function () {
    [$company, $invoice] = phase630Invoice(client: ['email_optional_2' => 'PRINCIPAL@example.com']);
    $admin = phase630User($company);
    phase630FakeEmail();

    phase630Send($admin, $invoice)
        ->assertOk()
        ->assertJsonPath('email.last_sent_at', fn (?string $value): bool => $value !== null);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), "/invoices/inv_mail_{$invoice->id}/email")
        && $request->data() === ['email' => ['principal@example.com', 'contador@example.com']]);

    $events = phase630Events($invoice, InvoicePacEventType::EmailSent);
    expect($events)->toHaveCount(1)
        ->and($events[0]->context)->toBe([
            'recipients' => ['principal@example.com', 'contador@example.com'],
            'recipient_count' => 2,
            'trigger' => 'manual',
            'user_id' => $admin->id,
        ]);
});

test('Admin y Contador pueden agregar y quitar destinatarios', function (UserRole $role) {
    [$company, $invoice] = phase630Invoice();
    phase630FakeEmail();

    phase630Send(phase630User($company, $role), $invoice, ['recipients' => [' otro@example.com ', 'principal@example.com']])->assertOk();

    Http::assertSent(fn (Request $request) => $request->data() === ['email' => ['otro@example.com', 'principal@example.com']]);
})->with([UserRole::Admin, UserRole::Accountant]);

test('Ventas envía a los destinatarios del cliente, pero no puede cambiarlos', function () {
    [$company, $invoice] = phase630Invoice();
    $sales = phase630User($company, UserRole::Sales);
    phase630FakeEmail();

    // Agregar uno o quitar uno: 403 sin contactar a Facturapi.
    phase630Send($sales, $invoice, ['recipients' => ['principal@example.com', 'contador@example.com', 'extra@example.com']])
        ->assertForbidden()->assertJsonPath('code', 'INVOICE_EMAIL_RECIPIENTS_LOCKED');
    phase630Send($sales, $invoice, ['recipients' => ['principal@example.com']])
        ->assertForbidden()->assertJsonPath('code', 'INVOICE_EMAIL_RECIPIENTS_LOCKED');
    Http::assertNothingSent();

    // La misma lista (en otro orden y mayúsculas) o sin lista: sí. Otra
    // factura: los dos 403 ya cuentan para el limitador de ésta.
    [, $second] = phase630Invoice(company: $company);
    phase630Send($sales, $second, ['recipients' => ['CONTADOR@example.com', 'principal@example.com']])->assertOk();
    phase630Send($sales, $second)->assertOk();
    Http::assertSentCount(2);
});

test('Empleado no puede enviar la factura por correo', function () {
    [$company, $invoice] = phase630Invoice();
    phase630FakeEmail();

    phase630Send(phase630User($company, UserRole::Employee), $invoice)->assertForbidden();
    Http::assertNothingSent();
});

test('las capacidades distinguen enviar de editar la lista por rol', function (UserRole $role, bool $send, bool $edit) {
    [$company, $invoice] = phase630Invoice();
    app(CurrentTenant::class)->clear();

    $response = $this->actingAs(phase630User($company, $role), 'api')->getJson("/api/invoices/{$invoice->id}/billing")->assertOk()
        ->assertJsonPath('actions.can_send_email', $send)
        ->assertJsonPath('actions.can_edit_email_recipients', $edit);

    expect($response->json('email'))->toBe($send ? [
        'default_recipients' => ['principal@example.com', 'contador@example.com'],
        'max_recipients' => 5,
        'last_sent_at' => null,
    ] : null);
})->with([
    'Admin' => [UserRole::Admin, true, true],
    'Contador' => [UserRole::Accountant, true, true],
    'Ventas' => [UserRole::Sales, true, false],
    'Empleado' => [UserRole::Employee, false, false],
]);

test('sólo se envía un CFDI vigente o cancelado', function (array $fiscal, bool $allowed) {
    [$company, $invoice] = phase630Invoice($fiscal);
    phase630FakeEmail();

    $response = phase630Send(phase630User($company), $invoice);

    $allowed ? $response->assertOk() : $response->assertStatus(409)->assertJsonPath('code', 'INVOICE_EMAIL_NOT_ALLOWED');
    $allowed ? Http::assertSentCount(1) : Http::assertNothingSent();
})->with([
    'vigente' => [[], true],
    'cancelado' => [['pac_status' => 'canceled', 'cancellation_status' => 'accepted'], true],
    'sin timbrar' => [['pac_external_id' => null, 'cfdi_uuid' => null, 'pac_status' => null, 'pac_issue_status' => null], false],
    'pendiente' => [['pac_status' => 'pending'], false],
]);

test('valida formato, máximo de 5 y lista vacía', function (array $recipients, int $status) {
    [$company, $invoice] = phase630Invoice();
    phase630FakeEmail();

    phase630Send(phase630User($company), $invoice, ['recipients' => $recipients])->assertStatus($status);
    Http::assertNothingSent();
})->with([
    'formato' => [['no-es-correo'], 422],
    'seis' => [array_map(fn (int $i): string => "c{$i}@example.com", range(1, 6)), 422],
    'vacía' => [[], 422],
]);

test('un cliente sin correo válido responde 422 sin contactar a Facturapi', function () {
    [$company, $invoice] = phase630Invoice(client: ['email' => 'roto', 'email_optional_1' => null]);
    phase630FakeEmail();

    phase630Send(phase630User($company), $invoice)->assertStatus(422)->assertJsonPath('code', 'INVOICE_EMAIL_NO_RECIPIENTS');
    Http::assertNothingSent();
});

test('ok:false queda como email_failed y responde 502', function () {
    [$company, $invoice] = phase630Invoice();
    phase630FakeEmail(['ok' => false]);

    phase630Send(phase630User($company), $invoice)->assertStatus(502)->assertJsonPath('code', 'INVOICE_EMAIL_NOT_SENT');

    $failed = phase630Events($invoice, InvoicePacEventType::EmailFailed);
    expect($failed)->toHaveCount(1)
        ->and($failed[0]->pac_code)->toBe('email_not_sent')
        ->and($failed[0]->context['reason'])->toBe('email_not_sent')
        ->and(phase630Events($invoice, InvoicePacEventType::EmailSent))->toBe([]);
});

test('un timeout o 5xx queda como email_uncertain, sin reintento', function () {
    [$company, $invoice] = phase630Invoice();
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('timeout');
    });

    phase630Send(phase630User($company), $invoice)->assertStatus(502)->assertJsonPath('code', 'INVOICE_EMAIL_UNCERTAIN');

    expect($attempts)->toBe(1)
        ->and(phase630Events($invoice, InvoicePacEventType::EmailUncertain))->toHaveCount(1);
});

test('el tope diario por empresa cuenta envíos confirmados e inciertos', function () {
    config(['billing.email.company_daily_limit' => 2]);
    [$company, $invoice] = phase630Invoice();
    [, $other] = phase630Invoice(company: $company);
    phase630FakeEmail();
    $admin = phase630User($company);

    phase630Send($admin, $invoice)->assertOk();
    phase630Send($admin, $other)->assertOk();
    phase630Send($admin, $invoice)->assertStatus(429)->assertJsonPath('code', 'INVOICE_EMAIL_DAILY_LIMIT');
    Http::assertSentCount(2);

    // Un envío de hace más de 24 h ya no cuenta.
    InvoicePacEvent::withoutGlobalScopes()->where('company_id', $company->id)->update(['occurred_at' => now()->subDay()->subMinute()]);
    phase630Send($admin, $invoice)->assertOk();
});

test('el limitador frena más de 3 envíos de la misma factura en 10 minutos', function () {
    [$company, $invoice] = phase630Invoice();
    phase630FakeEmail();
    $admin = phase630User($company);

    foreach (range(1, 3) as $ignored) {
        phase630Send($admin, $invoice)->assertOk();
    }

    phase630Send($admin, $invoice)->assertStatus(429);
    Http::assertSentCount(3);
});

test('una factura de otra organización no se envía', function () {
    [$company, $invoice] = phase630Invoice(['pac_organization_id' => 'org_ajena_630']);
    phase630FakeEmail();

    phase630Send(phase630User($company), $invoice)->assertStatus(409)->assertJsonPath('code', 'PAC_ORGANIZATION_MISMATCH');
    Http::assertNothingSent();
});

test('el historial muestra a quién y cómo; el log sólo correos enmascarados', function () {
    [$company, $invoice] = phase630Invoice();
    $admin = phase630User($company);
    phase630FakeEmail();
    Log::spy();

    phase630Send($admin, $invoice)->assertOk();
    $timeline = collect($this->actingAs($admin, 'api')->getJson("/api/invoices/{$invoice->id}/billing")->json('timeline'));

    expect($timeline->firstWhere('type', 'email_sent')['context'])->toBe([
        'recipients' => ['principal@example.com', 'contador@example.com'],
        'recipient_count' => 2,
        'trigger' => 'manual',
    ]);
    Log::shouldHaveReceived('log')->withArgs(fn (string $level, string $message, array $context = []): bool => $message === 'billing.invoice.email_sent'
        && $context['recipients'] === ['p***@example.com', 'c***@example.com'])->once();
});

test('exige confirm', function () {
    [$company, $invoice] = phase630Invoice();
    Http::fake();
    app(CurrentTenant::class)->clear();

    $this->actingAs(phase630User($company), 'api')->postJson("/api/invoices/{$invoice->id}/operations/email", [])->assertUnprocessable();
    Http::assertNothingSent();
});
