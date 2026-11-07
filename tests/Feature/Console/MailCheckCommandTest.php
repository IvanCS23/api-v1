<?php

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * Fase 6.32 (4): mail:check revisa el correo transaccional sin enviar nada.
 */
function phase632GoodMail(): void
{
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.proveedor.test',
        'mail.mailers.smtp.port' => 587,
        'mail.mailers.smtp.username' => 'usuario-secreto',
        'mail.mailers.smtp.password' => 'clave-secreta',
        'mail.from.address' => 'no-reply@facturapro.test',
        'app.frontend_url' => 'https://app.facturapro.test',
        'queue.default' => 'database',
    ]);
}

test('una configuración completa pasa y no imprime las credenciales', function () {
    phase632GoodMail();
    Mail::fake();
    Notification::fake();

    $this->artisan('mail:check')
        ->expectsOutputToContain('Correo transaccional: OK.')
        ->doesntExpectOutputToContain('usuario-secreto')
        ->doesntExpectOutputToContain('clave-secreta')
        ->assertExitCode(0);

    Mail::assertNothingSent();
    Notification::assertNothingSent();
});

test('en producción falla con log, array, remitente de ejemplo, URL local o cola sync', function (array $override, string $message) {
    phase632GoodMail();
    config($override);
    app()['env'] = 'production';

    $this->artisan('mail:check')
        ->expectsOutputToContain($message)
        ->expectsOutputToContain('FALLA (producción)')
        ->assertExitCode(1);
})->with([
    'log' => [['mail.default' => 'log'], 'laravel.log'],
    'array' => [['mail.default' => 'array'], 'ningún correo se entrega'],
    'remitente de ejemplo' => [['mail.from.address' => 'hello@example.com'], 'MAIL_FROM_ADDRESS'],
    'URL local' => [['app.frontend_url' => 'http://localhost:3000'], 'FRONTEND_URL'],
    'cola sync' => [['queue.default' => 'sync'], 'La cola es sync'],
]);

test('fuera de producción los mismos casos son avisos', function () {
    phase632GoodMail();
    config(['mail.default' => 'log']);

    $this->artisan('mail:check')
        ->expectsOutputToContain('avisos')
        ->assertExitCode(0);
});

test('--connect informa si no puede conectar, sin enviar nada', function () {
    phase632GoodMail();
    config(['mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);
    app()['env'] = 'production';

    $this->artisan('mail:check', ['--connect' => true])
        ->expectsOutputToContain('No se pudo conectar a 127.0.0.1:1')
        ->assertExitCode(1);
});
