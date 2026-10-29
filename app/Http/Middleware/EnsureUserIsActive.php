<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fase 6.27b (0b): rechaza a un usuario que ya no está activo aunque
 * conserve una credencial válida (token de API emitido antes de la
 * desactivación o sesión web abierta). Va después de la autenticación en
 * todas las rutas autenticadas.
 */
class EnsureUserIsActive
{
    public const CODE = 'USER_INACTIVE';

    public const MESSAGE = 'Tu cuenta está desactivada. Contacta a un administrador.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->status === UserStatus::Active) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['code' => self::CODE, 'message' => self::MESSAGE], 403);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        abort(403, self::MESSAGE);
    }
}
