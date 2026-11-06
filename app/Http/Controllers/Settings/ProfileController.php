<?php

namespace App\Http\Controllers\Settings;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Services\AuditLogger;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        // Fase 6.31: sólo el nombre; el correo lo cambia un Admin por la API.
        $user = $request->user();
        $previous = $user->name;
        $user->forceFill(['name' => $request->validated('name')]);

        if ($user->isDirty('name')) {
            $user->save();
            AuditLogger::record($user, AuditAction::ProfileUpdated, $request, ['fields' => ['name'], 'previous_name' => $previous], $user);
        }

        return to_route('profile.edit');
    }

    /**
     * Fase 6.31: nadie elimina su propia cuenta, igual que en la API
     * (UserController@destroy: "No puedes desactivar tu propia cuenta").
     * Antes este endpoint hacía un borrado suave del propio usuario sin
     * CompanyAdminGuard: el último Admin activo de una empresa podía dejarla
     * sin administrador. La ruta se conserva para responder con un mensaje
     * claro; el usuario queda intacto y activo.
     */
    public function destroy(Request $request): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['code' => self::SELF_DELETION_CODE, 'message' => self::SELF_DELETION_MESSAGE], 403);
        }

        return back()->withErrors(['account' => self::SELF_DELETION_MESSAGE]);
    }

    public const SELF_DELETION_CODE = 'SELF_DELETION_NOT_ALLOWED';

    public const SELF_DELETION_MESSAGE = 'No puedes eliminar tu propia cuenta. Pide a un administrador de tu empresa que la desactive.';
}
