<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Users\PasswordChangeSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

/**
 * Fase 6.32 — Recuperación de contraseña por correo, sin sesión.
 *
 * POST /api/password/forgot responde SIEMPRE 202 con el mismo cuerpo:
 * exista o no el correo, esté inactivo o lo haya frenado el límite por
 * correo de Laravel. Un usuario inactivo no recibe nada (D6).
 *
 * POST /api/password/reset usa el broker de Laravel (token hasheado, un
 * solo uso, 30 minutos). Al restablecer: rota/revoca el api_token, fija
 * password_changed_at, limpia must_change_password, cierra sesiones web,
 * borra los demás enlaces y audita. No reactiva a un usuario inactivo:
 * para él el enlace se trata como inválido.
 */
class PasswordRecoveryController extends Controller
{
    public const FORGOT_MESSAGE = 'Si el correo está registrado, te enviamos un enlace para restablecer tu contraseña. Revisa tu bandeja de entrada.';

    public const INVALID_CODE = 'PASSWORD_RESET_INVALID';

    public const INVALID_MESSAGE = 'El enlace para restablecer la contraseña no es válido o ya venció. Solicita uno nuevo.';

    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->validated('email'))->first();

        if ($user !== null && $user->status === UserStatus::Active) {
            // RESET_THROTTLED (un enlace por minuto por correo) se ignora:
            // la respuesta no debe distinguirlo.
            Password::broker()->sendResetLink(['email' => $user->email]);
        }

        return response()->json(['message' => self::FORGOT_MESSAGE], 202);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password', 'password_confirmation', 'token');
        $user = User::query()->where('email', $credentials['email'])->first();

        if ($user === null || $user->status !== UserStatus::Active) {
            return $this->invalid();
        }

        $status = Password::broker()->reset($credentials, function (User $user, string $password) use ($request): void {
            $user->forceFill([
                'password' => $password,
                'must_change_password' => false,
                ...PasswordChangeSecurity::fields(),
            ])->save();

            PasswordChangeSecurity::afterChange($user);
            AuditLogger::record($user, AuditAction::PasswordChanged, $request, ['source' => 'api_password_reset']);
        });

        return $status === Password::PASSWORD_RESET
            ? response()->json(['message' => 'Tu contraseña se restableció. Inicia sesión con la nueva.'])
            : $this->invalid();
    }

    private function invalid(): JsonResponse
    {
        return response()->json(['code' => self::INVALID_CODE, 'message' => self::INVALID_MESSAGE], 422);
    }
}
