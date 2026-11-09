<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;

/**
 * Fase 6.33 (1): lo que corrige Laravel 12.71 y nos afecta.
 *
 * - TokenGuard: un `api_token` que no es texto (arreglo, objeto, número,
 *   booleano) nunca autentica y responde 401, no 500. Con Laravel 12.54 un
 *   arreglo llegaba a hash() y terminaba en 500 (con `hash => true` no se
 *   comparaba sin hashear, así que no autenticaba).
 * - Regla `email`: un correo con salto de línea se rechaza (inyección de
 *   encabezados en el correo).
 * - Correo de recuperación en Markdown (escape endurecido en 12.70.1): el
 *   HTML se arma con el enlace correcto.
 */
function phase633TokenUser(): array
{
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Sales]);
    $plain = 'token-plano-'.$user->id;
    $user->forceFill(['api_token' => hash('sha256', $plain)])->save();

    return [$user, $plain];
}

test('el token en la query sí autentica (control)', function () {
    [, $plain] = phase633TokenUser();

    $this->get('/api/user?api_token='.$plain)->assertOk();
});

test('un api_token que no es texto responde 401, nunca 500 ni autentica', function (string $case) {
    [, $plain] = phase633TokenUser();

    $response = match ($case) {
        'arreglo en la query' => $this->get('/api/user?api_token[]='.$plain),
        'arreglo con llave en la query' => $this->get('/api/user?api_token[x]='.$plain),
        'arreglo en el cuerpo' => $this->json('GET', '/api/user', ['api_token' => [$plain]]),
        'objeto en el cuerpo' => $this->json('GET', '/api/user', ['api_token' => ['token' => $plain]]),
        'número en el cuerpo' => $this->json('GET', '/api/user', ['api_token' => 0]),
        'booleano en el cuerpo' => $this->json('GET', '/api/user', ['api_token' => true]),
    };

    $response->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
})->with([
    'arreglo en la query',
    'arreglo con llave en la query',
    'arreglo en el cuerpo',
    'objeto en el cuerpo',
    'número en el cuerpo',
    'booleano en el cuerpo',
]);

test('la regla email rechaza saltos de línea', function (string $rule, string $email) {
    expect(Validator::make(['email' => $email], ['email' => $rule])->fails())->toBeTrue()
        ->and(Validator::make(['email' => 'persona@example.com'], ['email' => $rule])->fails())->toBeFalse();
})->with([
    // Con 12.54 estos tres pasaban: el RFC permite saltos de línea plegados
    // (CRLF y espacio) en la parte local, entre comillas o en comentarios.
    ['email', "persona\r\n @example.com"],
    ['email', "\"per\r\n sona\"@example.com"],
    ['email:rfc', "persona(\r\n x)@example.com"],
    ['email', "persona@example.com\r\nBcc: otra@example.com"],
]);

test('la recuperación con un correo con salto de línea no envía nada', function () {
    Notification::fake();
    phase633TokenUser();

    $this->postJson('/api/password/forgot', ['email' => "persona\r\n @example.com"])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);

    Notification::assertNothingSent();
});

test('el correo de recuperación se arma en HTML con el enlace correcto', function () {
    config(['app.frontend_url' => 'https://app.example.test']);
    [$user] = phase633TokenUser();
    $token = 'tok_[abc]\\def<ghi>';

    $html = (string) (new ResetPasswordNotification($token))->toMail($user)->render();
    $url = ResetPasswordNotification::url($token, $user->email);

    expect($html)->toContain('href="'.e($url).'"')
        ->and($html)->toContain('Restablecer contraseña')
        ->and($html)->toContain('Hola')
        ->and($html)->toContain('El enlace vence en')
        ->and($html)->not->toContain('\\[')
        ->and($html)->not->toContain('<ghi>');
});
