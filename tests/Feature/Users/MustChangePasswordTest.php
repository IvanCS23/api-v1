<?php

use App\Enums\UserRole;
use App\Http\Middleware\EnsurePasswordIsCurrent;
use App\Models\Company;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

/**
 * Fase 6.32 (2): mientras must_change_password esté activo, la API sólo
 * permite ver el usuario, cerrar sesión y cambiar la contraseña.
 */
const PHASE_632_ALLOWED = ['GET api/user', 'POST api/logout', 'PUT api/me/password'];

function phase632FlaggedUser(bool $flag = true): User
{
    $company = Company::factory()->create();

    return User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Admin, 'must_change_password' => $flag]);
}

/** @return list<array{string, string}> rutas de la API con sesión, salvo las permitidas */
function phase632ProtectedApiRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/') || ! in_array('auth:api', $route->gatherMiddleware(), true)) {
            continue;
        }

        $method = collect($route->methods())->reject(fn (string $m): bool => $m === 'HEAD')->first();
        $key = $method.' '.$route->uri();

        if (! in_array($key, PHASE_632_ALLOWED, true)) {
            $routes[] = [$method, preg_replace('/\{[^}]+\}/', '1', $route->uri())];
        }
    }

    return $routes;
}

test('el middleware está en todas las rutas con sesión salvo las tres permitidas', function () {
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/') || ! in_array('auth:api', $route->gatherMiddleware(), true)) {
            continue;
        }

        $method = collect($route->methods())->reject(fn (string $m): bool => $m === 'HEAD')->first();
        $hasIt = in_array('password.current', $route->gatherMiddleware(), true)
            && ! in_array('password.current', $route->excludedMiddleware(), true);

        expect($hasIt)->toBe(! in_array($method.' '.$route->uri(), PHASE_632_ALLOWED, true), $method.' '.$route->uri());
    }
});

test('con la bandera activa cualquier otra ruta responde 403 PASSWORD_CHANGE_REQUIRED', function () {
    // Son más de 80 rutas: sin el limitador general (60 por minuto).
    $this->withoutMiddleware(ThrottleRequests::class);
    $user = phase632FlaggedUser();
    $routes = phase632ProtectedApiRoutes();

    expect(count($routes))->toBeGreaterThan(80);

    foreach ($routes as [$method, $uri]) {
        $this->actingAs($user, 'api')
            ->json($method, '/'.$uri)
            ->assertStatus(403)
            ->assertJsonPath('code', EnsurePasswordIsCurrent::CODE);
    }
});

test('con la bandera activa puede ver su usuario, cerrar sesión y cambiar la contraseña', function () {
    $user = phase632FlaggedUser();

    $this->actingAs($user, 'api')->getJson('/api/user')->assertOk()->assertJsonPath('user.must_change_password', true);

    $this->actingAs($user, 'api')
        ->putJson('/api/me/password', ['current_password' => 'password', 'password' => 'Nueva-Contrasena-2026', 'password_confirmation' => 'Nueva-Contrasena-2026'])
        ->assertOk()
        ->assertJsonPath('user.must_change_password', false);

    expect($user->fresh()->must_change_password)->toBeFalse();

    $this->actingAs($user->fresh(), 'api')->getJson('/api/clients')->assertOk();
});

test('cerrar sesión funciona con la bandera activa', function () {
    $user = phase632FlaggedUser();

    $this->actingAs($user, 'api')->postJson('/api/logout')->assertOk();
});

test('sin la bandera nada cambia', function () {
    $user = phase632FlaggedUser(false);

    $this->actingAs($user, 'api')->getJson('/api/clients')->assertOk();
});
