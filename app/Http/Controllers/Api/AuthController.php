<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Authenticate the user and return an API token.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('web')->attempt($request->only('email', 'password'))) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales proporcionadas son incorrectas.'],
            ]);
        }

        /** @var \App\Models\User $user */
        $user = Auth::guard('web')->user();

        // Generate a new plain-text token, store its SHA-256 hash
        $plainToken = Str::random(80);
        $user->forceFill(['api_token' => hash('sha256', $plainToken)])->save();

        return response()->json([
            'token' => $plainToken,
            'user'  => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * Return the authenticated user.
     */
    public function user(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user('api')]);
    }

    /**
     * Revoke the user's API token.
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user('api');
        $user->forceFill(['api_token' => null])->save();

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }
}
