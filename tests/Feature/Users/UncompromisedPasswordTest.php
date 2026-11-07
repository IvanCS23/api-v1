<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\NotPwnedVerifier;

/**
 * Fase 6.32 (ajuste 5): comportamiento ACTUAL de uncompromised(), que en
 * producción forma parte de Password::defaults(). Estas pruebas no lo
 * cambian; sólo lo fijan para decidir sobre él:
 * - consulta api.pwnedpasswords.com con k-anonimato (sólo 5 caracteres del
 *   SHA-1) y un tiempo máximo de 30 s (conexión: 10 s);
 * - sólo consulta si la contraseña ya cumplió las demás reglas;
 * - si el servicio no responde, tarda más del límite o responde un error,
 *   Laravel reporta la excepción y ACEPTA la contraseña (falla abierto),
 *   sin 500.
 */
const PHASE_632_STRONG = 'Abcdef-12345';

beforeEach(function () {
    app()['env'] = 'production';
    Http::preventStrayRequests();
});

function phase632PwnedUser(): array
{
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Sales]);
    $plain = 'token-pwned-'.$user->id.'-'.str_repeat('p', 40);
    $user->forceFill(['api_token' => hash('sha256', $plain)])->save();

    return [$user, $plain];
}

function phase632ChangeTo(string $token, string $password)
{
    return test()->withHeader('Authorization', 'Bearer '.$token)->putJson('/api/me/password', [
        'current_password' => 'password',
        'password' => $password,
        'password_confirmation' => $password,
    ]);
}

test('el verificador es el de Laravel, con 30 segundos de espera máxima', function () {
    $verifier = app(UncompromisedVerifier::class);
    $timeout = (new ReflectionProperty(NotPwnedVerifier::class, 'timeout'))->getValue($verifier);

    expect($verifier)->toBeInstanceOf(NotPwnedVerifier::class)
        ->and($timeout)->toBe(30);
});

test('sólo envía los 5 primeros caracteres del hash', function () {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response("0000000000000000000000000000000000A:1\r\n")]);
    [, $token] = phase632PwnedUser();

    phase632ChangeTo($token, PHASE_632_STRONG)->assertOk();

    $prefix = substr(strtoupper(sha1(PHASE_632_STRONG)), 0, 5);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.pwnedpasswords.com/range/'.$prefix
        && ! str_contains($request->url(), PHASE_632_STRONG));
    Http::assertSentCount(1);
});

test('una contraseña filtrada se rechaza con el mensaje en español', function () {
    $hash = strtoupper(sha1(PHASE_632_STRONG));
    Http::fake(['api.pwnedpasswords.com/*' => Http::response(substr($hash, 5).":42\r\n")]);
    [$user, $token] = phase632PwnedUser();

    phase632ChangeTo($token, PHASE_632_STRONG)
        ->assertUnprocessable()
        ->assertJsonPath('errors.password.0', 'Esta contraseña aparece en filtraciones de datos conocidas. Elige otra.');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

test('si el servicio no responde, se reporta y la contraseña se acepta (falla abierto, sin 500)', function () {
    Exceptions::fake();
    Http::fake(['api.pwnedpasswords.com/*' => Http::failedConnection('cURL error 28: Operation timed out')]);
    [$user, $token] = phase632PwnedUser();

    phase632ChangeTo($token, PHASE_632_STRONG)->assertOk();

    Exceptions::assertReported(ConnectionException::class);
    expect(Hash::check(PHASE_632_STRONG, $user->fresh()->password))->toBeTrue();
});

test('si el servicio responde un error, la contraseña también se acepta', function (int $status) {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('Service Unavailable', $status)]);
    [$user, $token] = phase632PwnedUser();

    phase632ChangeTo($token, PHASE_632_STRONG)->assertOk();

    expect(Hash::check(PHASE_632_STRONG, $user->fresh()->password))->toBeTrue();
})->with([429, 500, 503]);

test('no se consulta el servicio si la contraseña falla otra regla', function () {
    Http::fake();
    [, $token] = phase632PwnedUser();

    phase632ChangeTo($token, 'Ab1-cdef')->assertUnprocessable();

    Http::assertNothingSent();
});
