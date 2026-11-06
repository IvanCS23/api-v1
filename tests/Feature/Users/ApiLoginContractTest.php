<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Auth\TokenGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Fase 6.32 (ajuste 4): contrato de POST /api/login antes de retirar la web
 * de Laravel y Fortify. El login de la API valida con el guard `web` (driver
 * session, sin guardar ninguna sesión) y emite el api_token del guard `api`.
 * Si alguna de estas pruebas falla tras el retiro, se quitó algo de lo que
 * depende el login.
 */
function phase632LoginUser(array $attributes = []): User
{
    $company = Company::factory()->create();

    return User::factory()->create(array_merge([
        'company_id' => $company->id,
        'role' => UserRole::Sales,
        'email' => 'login@example.com',
        'password' => 'Clave-Login-2026',
    ], $attributes));
}

function phase632Login(string $password = 'Clave-Login-2026', string $email = 'login@example.com')
{
    return test()->postJson('/api/login', ['email' => $email, 'password' => $password]);
}

test('la configuración de auth de la que depende el login sigue en su lugar', function () {
    expect(config('auth.defaults.guard'))->toBe('web')
        ->and(config('auth.guards.web'))->toBe(['driver' => 'session', 'provider' => 'users'])
        ->and(config('auth.guards.api'))->toBe(['driver' => 'token', 'provider' => 'users', 'hash' => true])
        ->and(config('auth.providers.users.driver'))->toBe('eloquent')
        ->and(config('auth.providers.users.model'))->toBe(User::class)
        ->and(config('auth.passwords.users.table'))->toBe('password_reset_tokens')
        ->and(config('auth.passwords.users.expire'))->toBe(30)
        ->and(Auth::guard('web'))->toBeInstanceOf(SessionGuard::class)
        ->and(Auth::guard('api'))->toBeInstanceOf(TokenGuard::class)
        ->and(app('session.store'))->not->toBeNull();
});

test('la ruta usa el grupo api con su límite y no el grupo web', function () {
    $route = collect(Route::getRoutes()->getRoutes())->first(fn ($route) => $route->uri() === 'api/login' && in_array('POST', $route->methods(), true));

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('api', 'throttle:10,1')
        ->and($route->gatherMiddleware())->not->toContain('web');
});

test('un login correcto emite un token que el guard api acepta', function () {
    $user = phase632LoginUser();

    $response = phase632Login()->assertOk()->assertJsonPath('user.email', 'login@example.com');
    $token = $response->json('token');

    expect($token)->toBeString()->toHaveLength(80)
        ->and($user->fresh()->api_token)->toBe(hash('sha256', $token))
        ->and($user->fresh()->last_login_at)->not->toBeNull();

    app('auth')->forgetGuards();
    $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('user.id', $user->id);
});

test('el login no guarda ninguna sesión web ni envía su cookie', function () {
    config(['session.driver' => 'database']);
    phase632LoginUser();

    $response = phase632Login()->assertOk();

    expect(DB::table('sessions')->count())->toBe(0)
        ->and(collect($response->headers->getCookies())->map->getName())->not->toContain(config('session.cookie'));
});

test('iniciar sesión otra vez reemplaza el token anterior', function () {
    phase632LoginUser();
    $first = phase632Login()->json('token');
    app('auth')->forgetGuards();
    $second = phase632Login()->json('token');
    app('auth')->forgetGuards();

    expect($second)->not->toBe($first);
    $this->withHeader('Authorization', 'Bearer '.$first)->getJson('/api/user')->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->withHeader('Authorization', 'Bearer '.$second)->getJson('/api/user')->assertOk();
});

test('credenciales incorrectas o cuenta inactiva no emiten token', function () {
    $user = phase632LoginUser();

    phase632Login('equivocada')->assertUnprocessable()->assertJsonValidationErrors(['email']);
    phase632Login('Clave-Login-2026', 'nadie@example.com')->assertUnprocessable();

    $user->forceFill(['status' => UserStatus::Inactive])->save();
    app('auth')->forgetGuards();
    phase632Login()->assertUnprocessable()->assertJsonPath('errors.email.0', 'Esta cuenta se encuentra desactivada. Contacta a un administrador.');

    expect($user->fresh()->api_token)->toBeNull();
});

test('con contraseña temporal el login responde la bandera y sólo deja ver /api/user', function () {
    phase632LoginUser(['must_change_password' => true]);

    $token = phase632Login()->assertOk()->assertJsonPath('user.must_change_password', true)->json('token');

    app('auth')->forgetGuards();
    $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/user')->assertOk();
    app('auth')->forgetGuards();
    $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/clients')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
});

test('el límite de 10 intentos por minuto sigue activo', function () {
    phase632LoginUser();

    foreach (range(1, 10) as $attempt) {
        phase632Login('equivocada')->assertUnprocessable();
    }

    phase632Login('equivocada')->assertStatus(429);
});
