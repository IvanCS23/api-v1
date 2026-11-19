<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Fase 6.35 (D10): el límite general de la API autenticada es de 120
 * peticiones por minuto por usuario; login y recuperación de contraseña
 * conservan sus límites estrictos.
 */
function phase635RouteMiddleware(string $method, string $uri): array
{
    return Route::getRoutes()->match(request()->create($uri, $method))->gatherMiddleware();
}

test('las rutas autenticadas tienen el límite de 120 por minuto', function () {
    expect(phase635RouteMiddleware('GET', '/api/user'))->toContain('throttle:120,1')
        ->and(phase635RouteMiddleware('GET', '/api/sales'))->toContain('throttle:120,1')
        ->and(phase635RouteMiddleware('GET', '/api/sales'))->not->toContain('throttle:60,1');
});

test('login y recuperación conservan sus límites estrictos', function () {
    expect(phase635RouteMiddleware('POST', '/api/login'))->toContain('throttle:10,1')
        ->and(phase635RouteMiddleware('POST', '/api/password/forgot'))->toContain('throttle:password-forgot')
        ->and(phase635RouteMiddleware('POST', '/api/password/reset'))->toContain('throttle:password-reset');
});

test('la petición 121 del mismo usuario en un minuto recibe 429; la 120 no', function () {
    $user = User::factory()->create(['company_id' => Company::factory()->create()->id]);

    foreach (range(1, 120) as $attempt) {
        $this->actingAs($user, 'api')->getJson('/api/user')->assertOk();
    }

    $this->actingAs($user, 'api')->getJson('/api/user')->assertStatus(429);
});
