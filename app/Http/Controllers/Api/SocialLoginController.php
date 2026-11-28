<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Enums\SocialProvider;
use App\Exceptions\SocialLoginException;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Auth\ApiTokenIssuer;
use App\Services\Auth\SocialLoginService;
use App\Services\Users\PasswordChangeSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Throwable;

/**
 * Login social para usuarios Client:
 *
 * 1. GET  /api/auth/{provider}/redirect  → manda al usuario a Google/Microsoft.
 * 2. GET  /api/auth/{provider}/callback  → valida y entrega un código de un
 *    solo uso: redirige a FRONTEND_SOCIAL_CALLBACK_URL?code=..., o lo
 *    responde en JSON si esa variable está vacía (pruebas sin frontend).
 * 3. POST /api/auth/social/exchange      → cambia el código por el token,
 *    con la misma respuesta que POST /api/auth/login.
 */
class SocialLoginController extends Controller
{
    public function __construct(
        private readonly SocialLoginService $social,
        private readonly ApiTokenIssuer $tokens,
    ) {}

    public function redirect(string $provider): RedirectResponse|JsonResponse
    {
        $provider = $this->provider($provider);

        if (! $provider->isConfigured()) {
            return $this->notConfigured();
        }

        $state = $this->social->createState($provider);

        return $this->driver($provider)->with(['state' => $state])->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse|JsonResponse
    {
        $provider = $this->provider($provider);

        if (! $provider->isConfigured()) {
            return $this->notConfigured();
        }

        if (! $this->social->consumeState($provider, $request->query('state'))) {
            return $this->finish(['error' => 'invalid_state']);
        }

        if ($request->filled('error')) {
            return $this->finish(['error' => 'access_denied']);
        }

        try {
            $socialUser = $this->driver($provider)->user();
        } catch (Throwable $e) {
            Log::warning('social_login.provider_error', [
                'provider' => $provider->value,
                'exception' => $e::class,
            ]);

            return $this->finish(['error' => 'provider_error']);
        }

        try {
            $user = $this->social->resolveUser($provider, $socialUser);
        } catch (SocialLoginException $e) {
            return $this->finish(['error' => $e->reason]);
        }

        return $this->finish(['code' => $this->social->createLoginCode($user)]);
    }

    public function exchange(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:128'],
        ]);

        try {
            $user = $this->social->consumeLoginCode($data['code']);
        } catch (SocialLoginException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => mb_strtoupper($e->reason),
            ], 422);
        }

        $plainToken = DB::transaction(function () use ($user, $request): string {
            if ($user->must_change_password === true) {
                $this->discardTemporaryPassword($user, $request);
            }

            return $this->tokens->issue($user, $request->ip());
        });

        return response()->json([
            'token' => $plainToken,
            'user' => new UserResource($user->load('company')),
        ]);
    }

    /**
     * Un Client con contraseña temporal que entra por Google o Microsoft no
     * la conoce, y password.current lo dejaría bloqueado (PUT /me/password
     * pide la actual). Su proveedor ya lo identificó, así que la temporal se
     * reemplaza por una al azar que nadie conoce: deja de servir en
     * /api/login, igual que los enlaces de recuperación pendientes. Si quiere
     * volver a usar contraseña, la restablece por correo.
     */
    private function discardTemporaryPassword(User $user, Request $request): void
    {
        $user->forceFill([
            'password' => Hash::make(Str::random(64)),
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();

        PasswordChangeSecurity::forgetResetTokensFor($user->email);

        // Sin usuario autenticado en el canje: el actor es el propio usuario.
        AuditLogger::record($user, AuditAction::PasswordChanged, $request, ['source' => 'social_login'], $user);
    }

    private function provider(string $provider): SocialProvider
    {
        return SocialProvider::tryFrom($provider) ?? abort(404);
    }

    /**
     * Las rutas de API no tienen sesión: Socialite trabaja sin estado y el
     * `state` lo valida SocialLoginService.
     *
     * @return AbstractProvider
     */
    private function driver(SocialProvider $provider)
    {
        $driver = Socialite::driver($provider->value)->stateless();

        if ($provider === SocialProvider::Microsoft) {
            // openid + email: el id_token trae email, tid y xms_edov.
            $driver->scopes(['openid', 'profile', 'email']);
        }

        return $driver;
    }

    /**
     * @param  array{code?: string, error?: string}  $params
     */
    private function finish(array $params): RedirectResponse|JsonResponse
    {
        $frontendUrl = config('services.social_login.frontend_callback_url');

        if (filled($frontendUrl)) {
            $separator = str_contains($frontendUrl, '?') ? '&' : '?';

            return redirect()->away($frontendUrl.$separator.http_build_query($params));
        }

        if (isset($params['code'])) {
            return response()->json(['code' => $params['code']]);
        }

        $reason = $params['error'] ?? 'provider_error';

        return response()->json([
            'message' => SocialLoginException::messageFor($reason),
            'code' => mb_strtoupper($reason),
        ], 422);
    }

    private function notConfigured(): JsonResponse
    {
        return response()->json([
            'message' => 'Este proveedor de inicio de sesión no está configurado.',
            'code' => 'SOCIAL_PROVIDER_NOT_CONFIGURED',
        ], 503);
    }
}
