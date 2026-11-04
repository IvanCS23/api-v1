<?php

use App\Contracts\Billing\PacProvider;
use App\Exceptions\Billing\PacConfigurationException;
use App\Exceptions\Billing\PacEmailNotSentException;
use App\Exceptions\Billing\PacRateLimitException;
use App\Exceptions\Billing\PacUnavailableException;
use App\Exceptions\Billing\PacValidationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Fase 6.30: FacturapiProvider::sendInvoiceEmail — POST /invoices/{id}/email
 * siempre con la lista explícita, sin reintentos (no es idempotente).
 */
beforeEach(function () {
    facturapiOrganizationsFromConfigKey();
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_PHASE_630_EMAIL',
    ]);
    Http::preventStrayRequests();
});

test('sendInvoiceEmail existe en el contrato PacProvider', function () {
    expect(method_exists(PacProvider::class, 'sendInvoiceEmail'))->toBeTrue();
});

test('envía POST /invoices/{id}/email con la lista explícita de destinatarios', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    facturapiProviderFromConfig()->sendInvoiceEmail('inv_mail_1', ['a@example.com', 'b@example.com']);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/v2/invoices/inv_mail_1/email')
        && $request->data() === ['email' => ['a@example.com', 'b@example.com']]
        && $request->hasHeader('Authorization', 'Bearer sk_test_PHASE_630_EMAIL'));
});

test('nunca envía un cuerpo vacío ni más de 10 destinatarios', function (array $recipients) {
    Http::fake();

    expect(fn () => facturapiProviderFromConfig()->sendInvoiceEmail('inv_mail_1', $recipients))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
})->with([
    'sin destinatarios' => [[]],
    'once destinatarios' => [array_map(fn (int $i): string => "c{$i}@example.com", range(1, 11))],
]);

test('ok:false o una respuesta sin ok es un rechazo definitivo', function (array $body) {
    Http::fake(['*' => Http::response($body, 200)]);

    expect(fn () => facturapiProviderFromConfig()->sendInvoiceEmail('inv_mail_1', ['a@example.com']))
        ->toThrow(PacEmailNotSentException::class);
})->with([
    'ok false' => [['ok' => false]],
    'sin ok' => [[]],
]);

test('los errores HTTP se mapean igual que el resto de operaciones', function (int $status, string $exception) {
    Http::fake(['*' => Http::response(['message' => 'error'], $status)]);

    expect(fn () => facturapiProviderFromConfig()->sendInvoiceEmail('inv_mail_1', ['a@example.com']))
        ->toThrow($exception);
})->with([
    'validación' => [400, PacValidationException::class],
    'límite' => [429, PacRateLimitException::class],
    'caída' => [500, PacUnavailableException::class],
]);

test('un fallo de conexión no se reintenta: el envío no es idempotente', function () {
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('timeout');
    });

    expect(fn () => facturapiProviderFromConfig()->sendInvoiceEmail('inv_mail_1', ['a@example.com']))
        ->toThrow(ConnectionException::class)
        ->and($attempts)->toBe(1);
});

test('una configuración PAC inválida no envía nada', function () {
    config(['services.facturapi.test_key' => 'sk_live_PHASE_630_EMAIL']);
    facturapiOrganizationsFromConfigKey();
    Http::fake();

    expect(fn () => facturapiProviderFromConfig()->sendInvoiceEmail('inv_mail_1', ['a@example.com']))
        ->toThrow(PacConfigurationException::class);

    Http::assertNothingSent();
});
