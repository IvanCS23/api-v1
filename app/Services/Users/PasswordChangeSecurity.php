<?php

namespace App\Services\Users;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
