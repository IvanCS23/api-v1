<?php

namespace App\Actions\Fortify;

use Illuminate\Http\Exceptions\HttpResponseException;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;

/**
 * Fase 6.31 — La 2FA web no se puede activar de nuevo. Sólo protegería la
 * web de Laravel: POST /api/login, el inicio de sesión de la aplicación,
 * todavía no la exige (pendiente del Hito 2).
 *
 * Reemplaza la acción de Fortify detrás de POST /user/two-factor-authentication.
 * A quien ya la tiene confirmada se le conserva el comportamiento original
 * (sin `force` no cambia nada); desactivarla y el reto al iniciar sesión no
 * pasan por aquí.
 */
final class RejectNewTwoFactorAuthentication extends EnableTwoFactorAuthentication
{
    public const CODE = 'TWO_FACTOR_UNAVAILABLE';

    public const MESSAGE = 'La autenticación en dos pasos todavía no está disponible: por ahora no protegería el inicio de sesión de la aplicación.';

    public function __invoke($user, $force = false)
    {
        if ($user->hasEnabledTwoFactorAuthentication()) {
            parent::__invoke($user, $force);

            return;
        }

        throw self::rejection();
    }

    public static function rejection(): HttpResponseException
    {
        $request = request();

        return new HttpResponseException($request->expectsJson()
            ? response()->json(['code' => self::CODE, 'message' => self::MESSAGE], 403)
            : back()->withErrors(['two_factor' => self::MESSAGE]));
    }
}
