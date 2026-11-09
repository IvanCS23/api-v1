<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Services\Security\PwnedPasswordVerifier;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fase 6.32 (decisión B): uncompromised(), que en producción forma parte de
 * Password::defaults(), usa PwnedPasswordVerifier:
 * - consulta api.pwnedpasswords.com con k-anonimato (sólo 5 caracteres del
 *   SHA-1);
 * - espera como máximo 3 s, para conectar y para responder;
 * - sólo consulta si la contraseña ya cumplió las demás reglas;
 * - si no hay conexión, se vence el tiempo o responde un error, ACEPTA la
 *   contraseña (falla abierto), sin 500, y registra el evento
 *   password_breach_check.unavailable sin la contraseña ni el prefijo.
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

/** El evento de falla se registró una vez y no contiene la contraseña, su hash ni el prefijo. */
function phase632AssertUnavailableLogged(array $expected): void
{
    $hash = strtoupper(sha1(PHASE_632_STRONG));

    Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use ($expected, $hash): bool {
        $dump = $message.json_encode($context);

        return $message === PwnedPasswordVerifier::EVENT
            && array_intersect_assoc($context, $expected) === $expected
            && $context['timeout_seconds'] === 3
            && $context['outcome'] === 'accepted_fail_open'
            && ! str_contains($dump, PHASE_632_STRONG)
            && ! str_contains(strtoupper($dump), substr($hash, 0, 5))
            && ! str_contains($dump, 'pwnedpasswords');
    });
}

test('el verificador es el propio, con 3 segundos de espera', function () {
    $verifier = app(UncompromisedVerifier::class);
    $timeout = (new ReflectionProperty($verifier, 'timeout'))->getValue($verifier);

    expect($verifier)->toBeInstanceOf(PwnedPasswordVerifier::class)
        ->and($timeout)->toBe(3)
        ->and(PwnedPasswordVerifier::TIMEOUT_SECONDS)->toBe(3);
});

test('la consulta lleva 3 s de espera para conectar y para responder, y sólo 5 caracteres del hash', function () {
    $options = null;
    Http::fake(function (Request $request, array $sent) use (&$options) {
        $options = $sent;

        return Http::response("0000000000000000000000000000000000A:1\r\n");
    });
    [, $token] = phase632PwnedUser();

    phase632ChangeTo($token, PHASE_632_STRONG)->assertOk();

    $prefix = substr(strtoupper(sha1(PHASE_632_STRONG)), 0, 5);
    expect($options['timeout'])->toBe(3)
        ->and($options['connect_timeout'])->toBe(3);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.pwnedpasswords.com/range/'.$prefix
        && $request->hasHeader('Add-Padding'));
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

test('sin conexión o con el tiempo vencido: falla abierto, evento propio en el log y nada reportado', function () {
    Exceptions::fake();
    Log::spy();
    Http::fake(['api.pwnedpasswords.com/*' => Http::failedConnection('cURL error 28: Operation timed out after 3001 milliseconds for https://api.pwnedpasswords.com/range/'.substr(strtoupper(sha1(PHASE_632_STRONG)), 0, 5))]);
    [$user, $token] = phase632PwnedUser();

    phase632ChangeTo($token, PHASE_632_STRONG)->assertOk();

    expect(Hash::check(PHASE_632_STRONG, $user->fresh()->password))->toBeTrue();
    phase632AssertUnavailableLogged(['reason' => 'connection', 'exception' => ConnectionException::class]);
    Exceptions::assertNothingReported();
});

test('si el servicio responde un error: falla abierto y evento con el estado', function (int $status) {
    Log::spy();
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('Service Unavailable', $status)]);
    [$user, $token] = phase632PwnedUser();

    phase632ChangeTo($token, PHASE_632_STRONG)->assertOk();

    expect(Hash::check(PHASE_632_STRONG, $user->fresh()->password))->toBeTrue();
    phase632AssertUnavailableLogged(['reason' => 'http_status', 'status' => $status]);
})->with([429, 500, 503]);

test('si el servicio responde bien no se registra nada', function () {
    Log::spy();
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);
    [, $token] = phase632PwnedUser();

    phase632ChangeTo($token, PHASE_632_STRONG)->assertOk();

    Log::shouldNotHaveReceived('warning');
});

test('no se consulta el servicio si la contraseña falla otra regla', function () {
    Http::fake();
    [, $token] = phase632PwnedUser();

    phase632ChangeTo($token, 'Ab1-cdef')->assertUnprocessable();

    Http::assertNothingSent();
});
