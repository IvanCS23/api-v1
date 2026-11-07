<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateMyPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Users\PasswordChangeSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Fase 6.32 — PUT /api/me/password: cualquier rol cambia su propia
 * contraseña con la actual.
 *
 * Rota el api_token (D1): la respuesta trae uno nuevo para el cliente que
 * hizo el cambio y cualquier otro cliente pierde el acceso. Además cierra
 * las sesiones web, borra los enlaces de recuperación pendientes, limpia
 * must_change_password y queda auditado.
 */
class MyPasswordController extends Controller
{
    public function update(UpdateMyPasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('api');
        $plainToken = Str::random(80);

        $user->forceFill([
            'password' => $request->validated('password'),
            'must_change_password' => false,
            ...PasswordChangeSecurity::fields(),
            'api_token' => hash('sha256', $plainToken),
        ])->save();

        PasswordChangeSecurity::afterChange($user);
        AuditLogger::record($user, AuditAction::PasswordChanged, $request, ['source' => 'api_self'], $user);

        return response()->json([
            'token' => $plainToken,
            'user' => new UserResource($user->load('company')),
        ]);
    }
}
