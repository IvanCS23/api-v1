<?php

use App\Enums\AuditAction;
use App\Enums\SocialProvider;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Auth\SocialLoginService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteTwoUser;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeader;

/*
 * Login social de punta a punta, con Socialite simulado (sin red). El
 * redirect sí usa el driver real: solo arma la URL del proveedor.
 */

function socialHttpConfigure(): void
{
    config([
        'services.google.client_id' => 'google-id',
        'services.google.client_secret' => 'google-secret',
        'services.google.redirect' => 'http://localhost:8000/api/auth/google/callback',
        'services.microsoft.client_id' => 'microsoft-id',
        'services.microsoft.client_secret' => 'microsoft-secret',
        'services.microsoft.redirect' => 'http://localhost:8000/api/auth/microsoft/callback',
        'services.microsoft.tenant' => 'common',
        'services.social_login.frontend_callback_url' => null,
    ]);
}

function socialHttpClient(array $attributes = []): User
{
    return User::factory()->client()->create(array_merge([
        'company_id' => Company::factory()->create()->id,
        'email' => 'cliente@example.com',
        'password' => 'Clave-Cliente-2026',
    ], $attributes));
}

/** Simula que Google devolvió a este usuario en el callback. */
function socialHttpFakeGoogle(string $email = 'cliente@example.com', bool $verified = true): void
{
    $socialUser = (new SocialiteTwoUser)
        ->setRaw(['sub' => 'g-1', 'email' => $email, 'email_verified' => $verified])
        ->map(['id' => 'g-1', 'email' => $email, 'name' => 'Cliente Google']);

    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('stateless')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($socialUser);

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
}

function socialHttpCallback(?string $state = null, array $query = [])
{
    $state ??= app(SocialLoginService::class)->createState(SocialProvider::Google);

    return getJson('/api/auth/google/callback?'.http_build_query(['state' => $state, 'code' => 'codigo-de-google', ...$query]));
}

/** Callback exitoso y canje: devuelve la respuesta del canje. */
function socialHttpLogin()
{
    socialHttpFakeGoogle();
    $code = socialHttpCallback()->assertOk()->json('code');

    return postJson('/api/auth/social/exchange', ['code' => $code]);
}

// --- redirect -------------------------------------------------------------

test('el redirect responde 503 si el proveedor no tiene credenciales', function (string $provider) {
    socialHttpConfigure();
    config(["services.{$provider}.client_id" => null]);

    getJson("/api/auth/{$provider}/redirect")
        ->assertStatus(503)
        ->assertExactJson([
            'message' => 'Este proveedor de inicio de sesión no está configurado.',
            'code' => 'SOCIAL_PROVIDER_NOT_CONFIGURED',
        ]);
})->with(['google', 'microsoft']);

test('un proveedor fuera de la lista da 404', function () {
    socialHttpConfigure();

    getJson('/api/auth/facebook/redirect')->assertNotFound();
    getJson('/api/auth/facebook/callback')->assertNotFound();
});

test('el redirect de Google manda al proveedor con un state válido', function () {
    socialHttpConfigure();

    $location = getJson('/api/auth/google/redirect')->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://accounts.google.com/')
        ->and($query['redirect_uri'])->toBe('http://localhost:8000/api/auth/google/callback')
        ->and(app(SocialLoginService::class)->consumeState(SocialProvider::Google, $query['state']))->toBeTrue();
});

test('el redirect de Microsoft usa el proveedor registrado y pide openid y email', function () {
    socialHttpConfigure();

    $location = getJson('/api/auth/microsoft/redirect')->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://login.microsoftonline.com/common/oauth2/v2.0/authorize')
        ->and(explode(' ', $query['scope']))->toContain('openid', 'profile', 'email')
        ->and(app(SocialLoginService::class)->consumeState(SocialProvider::Microsoft, $query['state']))->toBeTrue();
});

// --- callback -------------------------------------------------------------

test('el callback con un state inválido o repetido no llama al proveedor', function () {
    socialHttpConfigure();
    socialHttpClient();
    Socialite::shouldReceive('driver')->never();

    socialHttpCallback('state-inventado')
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'La solicitud de inicio de sesión expiró o no es válida. Inténtalo de nuevo.',
            'code' => 'INVALID_STATE',
        ]);

    getJson('/api/auth/google/callback?code=codigo-de-google')->assertUnprocessable()->assertJsonPath('code', 'INVALID_STATE');
});

test('el callback exitoso responde un código, nunca el token', function () {
    socialHttpConfigure();
    $client = socialHttpClient();
    socialHttpFakeGoogle();

    $response = socialHttpCallback()->assertOk();

    expect(array_keys($response->json()))->toBe(['code'])
        ->and($response->json('code'))->toHaveLength(64)
        ->and($client->fresh()->api_token)->toBeNull()
        ->and(SocialAccount::query()->sole()->user_id)->toBe($client->id);
});

test('con FRONTEND_SOCIAL_CALLBACK_URL el callback redirige con el código', function () {
    socialHttpConfigure();
    config(['services.social_login.frontend_callback_url' => 'http://localhost:3000/auth/social/callback']);
    socialHttpClient();
    socialHttpFakeGoogle();

    $location = socialHttpCallback()->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('http://localhost:3000/auth/social/callback?code=')
        ->and(array_keys($query))->toBe(['code'])
        ->and($query['code'])->toHaveLength(64);
});

test('con FRONTEND_SOCIAL_CALLBACK_URL los errores redirigen con ?error=', function () {
    socialHttpConfigure();
    config(['services.social_login.frontend_callback_url' => 'http://localhost:3000/auth/social/callback']);
    socialHttpClient(['role' => UserRole::Sales]);
    socialHttpFakeGoogle();

    socialHttpCallback()->assertRedirect('http://localhost:3000/auth/social/callback?error=role_not_allowed');
    socialHttpCallback('state-inventado')->assertRedirect('http://localhost:3000/auth/social/callback?error=invalid_state');
});

test('el callback rechaza a un usuario que no es Client', function () {
    socialHttpConfigure();
    socialHttpClient(['role' => UserRole::Admin]);
    socialHttpFakeGoogle();

    socialHttpCallback()->assertUnprocessable()->assertJsonPath('code', 'ROLE_NOT_ALLOWED')->assertJsonMissingPath('token');
});

test('si el usuario cancela en el proveedor no se pide su perfil', function () {
    socialHttpConfigure();
    Socialite::shouldReceive('driver')->never();

    socialHttpCallback(query: ['error' => 'access_denied'])->assertUnprocessable()->assertJsonPath('code', 'ACCESS_DENIED');
});

test('un fallo del proveedor responde provider_error', function () {
    socialHttpConfigure();
    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('stateless')->andReturnSelf();
    $provider->shouldReceive('user')->andThrow(new RuntimeException('Google no respondió'));
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    socialHttpCallback()->assertUnprocessable()->assertJsonPath('code', 'PROVIDER_ERROR');
});

// --- exchange -------------------------------------------------------------

test('el canje devuelve token y usuario, y el token funciona en /api/user', function () {
    socialHttpConfigure();
    $client = socialHttpClient();

    $response = socialHttpLogin()->assertOk()->assertJsonPath('user.id', $client->id)->assertJsonPath('user.role', 'client');
    $token = $response->json('token');

    expect(array_keys($response->json()))->toBe(['token', 'user'])
        ->and($token)->toBeString()->toHaveLength(80)
        ->and($client->fresh()->api_token)->toBe(hash('sha256', $token))
        ->and($client->fresh()->last_login_at)->not->toBeNull();

    app('auth')->forgetGuards();
    withHeader('Authorization', 'Bearer '.$token)->getJson('/api/user')->assertOk()->assertJsonPath('user.id', $client->id);
    app('auth')->forgetGuards();
    withHeader('Authorization', 'Bearer '.$token)->getJson('/api/products')->assertOk();
});

test('el canje tiene la misma forma que POST /api/login', function () {
    socialHttpConfigure();
    socialHttpClient();

    $social = socialHttpLogin()->assertOk()->json();
    app('auth')->forgetGuards();
    $password = postJson('/api/login', ['email' => 'cliente@example.com', 'password' => 'Clave-Cliente-2026'])->assertOk()->json();

    expect(array_keys($social))->toBe(array_keys($password))
        ->and(array_keys($social['user']))->toBe(array_keys($password['user']))
        ->and($social['user']['id'])->toBe($password['user']['id']);
});

test('un código repetido, inventado o vacío da 422', function () {
    socialHttpConfigure();
    socialHttpClient();
    socialHttpFakeGoogle();
    $code = socialHttpCallback()->json('code');

    postJson('/api/auth/social/exchange', ['code' => $code])->assertOk();
    postJson('/api/auth/social/exchange', ['code' => $code])
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'El código de inicio de sesión no es válido o ya expiró.',
            'code' => 'INVALID_CODE',
        ]);

    postJson('/api/auth/social/exchange', ['code' => 'inventado'])->assertUnprocessable()->assertJsonPath('code', 'INVALID_CODE');
    postJson('/api/auth/social/exchange', [])->assertUnprocessable()->assertJsonValidationErrors(['code']);
});

test('el canje tiene límite de 10 intentos por minuto', function () {
    foreach (range(1, 10) as $attempt) {
        postJson('/api/auth/social/exchange', ['code' => 'inventado'])->assertUnprocessable();
    }

    postJson('/api/auth/social/exchange', ['code' => 'inventado'])->assertStatus(429);
});

// --- contraseña temporal (must_change_password) -----------------------------

function socialHttpPendingResetToken(string $email): void
{
    DB::table('password_reset_tokens')->insert([
        'email' => $email,
        'token' => Hash::make('enlace-viejo'),
        'created_at' => now(),
    ]);
}

test('un Client con contraseña temporal entra con Google y ya no queda bloqueado', function () {
    socialHttpConfigure();
    $client = socialHttpClient(['must_change_password' => true]);
    $temporaryHash = $client->password;
    socialHttpPendingResetToken('cliente@example.com');

    $response = socialHttpLogin()->assertOk()->assertJsonPath('user.must_change_password', false);
    $token = $response->json('token');
    $client->refresh();

    expect($client->must_change_password)->toBeFalse()
        ->and($client->password)->not->toBe($temporaryHash)
        ->and($client->password_changed_at)->not->toBeNull()
        ->and($client->api_token)->toBe(hash('sha256', $token))
        ->and(DB::table('password_reset_tokens')->where('email', 'cliente@example.com')->exists())->toBeFalse();

    $audit = AuditLog::query()->sole();
    expect($audit->action)->toBe(AuditAction::PasswordChanged->value)
        ->and($audit->user_id)->toBe($client->id)
        ->and($audit->actor_id)->toBe($client->id)
        ->and($audit->metadata)->toBe(['source' => 'social_login']);

    // Rutas con password.current: antes 403 PASSWORD_CHANGE_REQUIRED.
    app('auth')->forgetGuards();
    withHeader('Authorization', 'Bearer '.$token)->getJson('/api/products')->assertOk();
    app('auth')->forgetGuards();
    withHeader('Authorization', 'Bearer '.$token)->getJson('/api/clients')->assertOk();
});

test('tras entrar con Google la contraseña temporal ya no sirve en /api/login', function () {
    socialHttpConfigure();
    socialHttpClient(['must_change_password' => true]);

    // Antes del login social, la temporal sí funcionaba.
    postJson('/api/login', ['email' => 'cliente@example.com', 'password' => 'Clave-Cliente-2026'])->assertOk();
    app('auth')->forgetGuards();

    $socialToken = socialHttpLogin()->assertOk()->json('token');
    app('auth')->forgetGuards();

    postJson('/api/login', ['email' => 'cliente@example.com', 'password' => 'Clave-Cliente-2026'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'Las credenciales proporcionadas son incorrectas.');

    // El intento fallido no toca la sesión social.
    app('auth')->forgetGuards();
    withHeader('Authorization', 'Bearer '.$socialToken)->getJson('/api/products')->assertOk();
});

test('un Client sin contraseña temporal conserva su contraseña al entrar con Google', function () {
    socialHttpConfigure();
    $client = socialHttpClient();
    $hash = $client->password;
    socialHttpPendingResetToken('cliente@example.com');

    socialHttpLogin()->assertOk()->assertJsonPath('user.must_change_password', false);
    $client->refresh();

    expect($client->password)->toBe($hash)
        ->and($client->password_changed_at)->toBeNull()
        ->and(DB::table('password_reset_tokens')->where('email', 'cliente@example.com')->exists())->toBeTrue()
        ->and(AuditLog::query()->count())->toBe(0);

    app('auth')->forgetGuards();
    postJson('/api/login', ['email' => 'cliente@example.com', 'password' => 'Clave-Cliente-2026'])->assertOk();
});

// --- el login con contraseña no cambia --------------------------------------

test('el login con contraseña sigue igual, también para un Client', function () {
    $client = socialHttpClient();

    $response = postJson('/api/login', ['email' => 'cliente@example.com', 'password' => 'Clave-Cliente-2026'])
        ->assertOk()
        ->assertJsonPath('user.email', 'cliente@example.com');
    $token = $response->json('token');

    expect(array_keys($response->json()))->toBe(['token', 'user'])
        ->and($token)->toHaveLength(80)
        ->and($client->fresh()->api_token)->toBe(hash('sha256', $token))
        ->and(SocialAccount::query()->count())->toBe(0);

    app('auth')->forgetGuards();
    postJson('/api/login', ['email' => 'cliente@example.com', 'password' => 'equivocada'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'Las credenciales proporcionadas son incorrectas.');
});

test('el login social reemplaza el token del login con contraseña, igual que un segundo login', function () {
    socialHttpConfigure();
    socialHttpClient();
    $passwordToken = postJson('/api/login', ['email' => 'cliente@example.com', 'password' => 'Clave-Cliente-2026'])->json('token');
    app('auth')->forgetGuards();

    $socialToken = socialHttpLogin()->json('token');
    app('auth')->forgetGuards();

    withHeader('Authorization', 'Bearer '.$passwordToken)->getJson('/api/user')->assertUnauthorized();
    app('auth')->forgetGuards();
    withHeader('Authorization', 'Bearer '.$socialToken)->getJson('/api/user')->assertOk();
});
