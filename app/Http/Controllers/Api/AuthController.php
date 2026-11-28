<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\ApiTokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly ApiTokenIssuer $tokens) {}

    /**
     * Authenticate the user and return an API token.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('web')->attempt($request->only('email', 'password'))) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales proporcionadas son incorrectas.'],
            ]);
        }

        /** @var User $user */
        $user = Auth::guard('web')->user();

        if ($user->status !== UserStatus::Active) {
            Auth::guard('web')->logout();

            throw ValidationException::withMessages([
                'email' => ['Esta cuenta se encuentra desactivada. Contacta a un administrador.'],
            ]);
        }

        $plainToken = $this->tokens->issue($user, $request->ip());

        return response()->json([
            'token' => $plainToken,
            'user' => new UserResource($user->load('company')),
        ]);
    }

    /**
     * Return the authenticated user.
     */
    public function user(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('api');

        return response()->json(['user' => new UserResource($user->load('company'))]);
    }

    /**
     * Revoke the user's API token.
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('api');
        $user->forceFill(['api_token' => null])->save();

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }
}
