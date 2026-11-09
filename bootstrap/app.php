<?php

use App\Http\Middleware\EnsurePasswordIsCurrent;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\TenantMiddleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(HandleCors::class);

        $middleware->api(append: [
            TenantMiddleware::class,
        ]);

        // Fase 6.27b (0b): EnsureUserIsActive corre después de autenticar y
        // antes del controlador. No queda inmediatamente después: el orden
        // real es Authenticate > Throttle > SubstituteBindings > grupo
        // (TenantMiddleware...) > EnsureUserIsActive (verificado en la 6.31).
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            // Fase 6.32: must_change_password.
            'password.current' => EnsurePasswordIsCurrent::class,
        ]);
        $middleware->appendToPriorityList(Authenticate::class, EnsureUserIsActive::class);

        // Fase 6.32: ya no hay web ni ruta `login`. Un invitado nunca se
        // redirige (Laravel apuntaba a route('login'): sin esa ruta, 500).
        $middleware->redirectGuestsTo(fn (): ?string => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Fase 6.27b: la contraseña y los archivos del CSD nunca se
        // conservan en la sesión tras un error de validación.
        $exceptions->dontFlash(['csd_password', 'cer', 'key']);

        // Fase 6.32: sólo queda la API. En api/* los errores son JSON aunque
        // la petición no pida JSON (validación: 422, no una redirección).
        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => $request->is('api/*') || $request->expectsJson());

        // Y sin sesión, 401 en JSON en cualquier ruta: nunca route('login').
        $exceptions->render(fn (AuthenticationException $e) => response()->json(['message' => $e->getMessage()], 401));
    })->create();
