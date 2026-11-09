<?php

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

/**
 * Fase 6.32 (retiro de la web): sólo quedan las rutas de la API y /up. Sin
 * ruta `login`, ningún invitado se redirige: en api/* los errores son JSON
 * aunque la petición no lo pida y sin sesión la respuesta es 401, nunca 500.
 */
function phase632Routes()
{
    return collect(Route::getRoutes()->getRoutes());
}

test('sólo quedan rutas api/* y up', function () {
    $others = phase632Routes()
        ->reject(fn ($route) => str_starts_with($route->uri(), 'api/') || $route->uri() === 'up')
        ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($others)->toBe([])
        ->and(phase632Routes()->contains(fn ($route) => $route->uri() === 'up'))->toBeTrue()
        ->and(Route::has('login'))->toBeFalse()
        ->and(Route::has('password.reset'))->toBeFalse()
        ->and(phase632Routes()->contains(fn ($route) => in_array('web', $route->gatherMiddleware(), true)))->toBeFalse();
});

test('/up sigue respondiendo 200', function () {
    $this->get('/up')->assertOk();
});

test('sin sesión y sin Accept: 401 en JSON, sin redirección', function () {
    $response = $this->get('/api/user');

    $response->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
    expect($response->headers->get('Location'))->toBeNull()
        ->and($response->headers->get('Content-Type'))->toContain('application/json');
});

test('con un token inválido y sin Accept: 401 en JSON', function () {
    $this->withHeader('Authorization', 'Bearer token-que-no-existe')->get('/api/clients')
        ->assertUnauthorized()
        ->assertJson(['message' => 'Unauthenticated.']);
});

test('un error de validación sin Accept: 422 en JSON, sin redirección', function () {
    $response = $this->post('/api/login', ['email' => 'no-es-correo']);

    $response->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    expect($response->headers->get('Location'))->toBeNull();
});

test('toda ruta autenticada responde 401 sin sesión ni Accept, nunca 500 ni redirección', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    $checked = 0;

    foreach (phase632Routes() as $route) {
        if (! collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'auth'))) {
            continue;
        }

        $uri = '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $response = $this->call($method, $uri);
            expect($response->getStatusCode())->toBe(401, "{$method} {$uri}")
                ->and($response->headers->get('Location'))->toBeNull();
            $checked++;
        }
    }

    expect($checked)->toBeGreaterThan(80);
});

test('ninguna ruta abre una sesión: SESSION_DRIVER ya no necesita ser database', function () {
    $router = app('router');

    $withSession = phase632Routes()
        ->filter(fn ($route) => in_array(StartSession::class, $router->gatherRouteMiddleware($route), true))
        ->map(fn ($route) => $route->uri())
        ->values()
        ->all();

    expect($withSession)->toBe([]);
});
