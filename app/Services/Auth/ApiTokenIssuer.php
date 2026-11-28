<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Emite el token de API (guard `api`, driver `token` con hash). Lo usan el
 * login con correo y contraseña y el login social, para que ambos generen
 * la sesión exactamente igual: un token nuevo reemplaza al anterior.
 */
final class ApiTokenIssuer
{
    /**
     * @return string El token en texto plano (solo se entrega una vez).
     */
    public function issue(User $user, ?string $ip): string
    {
        // Generate a new plain-text token, store its SHA-256 hash
        $plainToken = Str::random(80);

        $user->forceFill([
            'api_token' => hash('sha256', $plainToken),
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ])->save();

        return $plainToken;
    }
}
