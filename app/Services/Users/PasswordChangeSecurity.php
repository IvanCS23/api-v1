<?php

namespace App\Services\Users;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/**
 * Fase 6.31 — Lo que debe pasar cada vez que una contraseña cambia de verdad
 * (cambio por la API o la web, restablecimiento por la API o por correo):
 *
 * - el api_token se revoca y password_changed_at se fija (fields());
 * - se cierran las sesiones web del usuario (terminateWebSessions()),
 *   salvo la actual si es el propio usuario quien la cambia.
 *
 * Es explícito en cada camino y no un hook de User: Laravel vuelve a
 * hashear la contraseña al iniciar sesión (Auth::attempt y el 2FA de
 * Fortify, `rehash_on_login`) y un hook no distingue ese re-hash de un
 * cambio real; revocaría el token en cada login.
 */
final class PasswordChangeSecurity
{
    /** @return array{api_token: null, password_changed_at: Carbon} */
    public static function fields(): array
    {
        return ['api_token' => null, 'password_changed_at' => now()];
    }

    /**
     * Fase 6.32: lo que sigue a guardar la contraseña nueva en cualquier
     * camino: cierra las sesiones web (salvo la indicada) y borra los
     * enlaces de recuperación pendientes, para que uno viejo deje de servir.
     */
    public static function afterChange(User $user, ?string $exceptSessionId = null): void
    {
        self::terminateWebSessions($user, $exceptSessionId);
        self::forgetResetTokens($user);
    }

    /** Fase 6.32: invalida los enlaces de recuperación pendientes del usuario. */
    public static function forgetResetTokens(User $user): void
    {
        Password::broker()->deleteToken($user);
    }

    /** Fase 6.32: lo mismo, por correo (el anterior, cuando un Admin lo cambia). */
    public static function forgetResetTokensFor(string $email): void
    {
        DB::table((string) config('auth.passwords.'.config('auth.defaults.passwords').'.table'))
            ->where('email', $email)
            ->delete();
    }

    /**
     * Borra las sesiones web del usuario. Sólo funciona con el driver
     * `database` (el de desarrollo y el recomendado en DEPLOYMENT.md); con
     * otro driver no hace nada y devuelve null (deuda documentada).
     */
    public static function terminateWebSessions(User $user, ?string $exceptSessionId = null): ?int
    {
        if (config('session.driver') !== 'database') {
            return null;
        }

        return DB::connection(config('session.connection'))
            ->table((string) config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->when($exceptSessionId !== null, fn ($query) => $query->where('id', '!=', $exceptSessionId))
            ->delete();
    }
}
