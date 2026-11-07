<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fase 6.32 — Mientras el usuario tenga must_change_password (contraseña
 * temporal que le dio un Admin), la API sólo le permite ver su usuario,
 * cerrar sesión y cambiar su contraseña: esas tres rutas quitan este
 * middleware explícitamente (routes/api.php). Todo lo demás responde
 * 403 PASSWORD_CHANGE_REQUIRED. El backend es la autoridad; Next.js sólo
 * lleva al usuario a la pantalla de cambio.
 */
class EnsurePasswordIsCurrent
{
    public const CODE = 'PASSWORD_CHANGE_REQUIRED';

    public const MESSAGE = 'Debes cambiar tu contraseña antes de continuar.';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user('api')?->must_change_password === true) {
            return response()->json(['code' => self::CODE, 'message' => self::MESSAGE], 403);
        }

        return $next($request);
    }
}
