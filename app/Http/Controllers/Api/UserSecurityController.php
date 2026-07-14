<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSecurityController extends Controller
{
    public function changePassword(ChangePasswordRequest $request, string $id): JsonResponse
    {
        $user = $this->findWithinCompany($request, $id);
        $validated = $request->validated();

        $user->update([
            'password' => Hash::make($validated['password']),
            'password_changed_at' => now(),
            'must_change_password' => $validated['must_change_password'] ?? false,
            'api_token' => null,
        ]);

        AuditLogger::record($user, AuditAction::PasswordChanged, $request);

        return response()->json(['user' => new UserResource($user->load('company'))]);
    }

    public function resetPassword(Request $request, string $id): JsonResponse
    {
        $user = $this->findWithinCompany($request, $id);

        $request->validate([
            'must_change_password' => ['nullable', 'boolean'],
        ]);

        $temporaryPassword = Str::password(12);

        $user->update([
            'password' => Hash::make($temporaryPassword),
            'password_changed_at' => now(),
            'must_change_password' => $request->boolean('must_change_password', true),
            'api_token' => null,
        ]);

        AuditLogger::record($user, AuditAction::PasswordReset, $request);

        return response()->json([
            'user' => new UserResource($user->load('company')),
            'temporary_password' => $temporaryPassword,
        ]);
    }

    public function revokeSessions(Request $request, string $id): JsonResponse
    {
        $user = $this->findWithinCompany($request, $id);

        if ($user->id === $request->user('api')->id) {
            return response()->json([
                'message' => 'No puedes cerrar tu propia sesión desde aquí.',
            ], 422);
        }

        $user->update(['api_token' => null]);

        AuditLogger::record($user, AuditAction::SessionsRevoked, $request);

        return response()->json(['message' => 'Sesiones cerradas correctamente.']);
    }

    public function updateMustChangePassword(Request $request, string $id): JsonResponse
    {
        $user = $this->findWithinCompany($request, $id);

        $validated = $request->validate([
            'must_change_password' => ['required', 'boolean'],
        ]);

        $user->update(['must_change_password' => $validated['must_change_password']]);

        return response()->json(['user' => new UserResource($user->load('company'))]);
    }

    private function findWithinCompany(Request $request, string $id): User
    {
        return User::query()
            ->where('company_id', $request->user('api')->company_id)
            ->findOrFail($id);
    }
}
