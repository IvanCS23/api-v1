<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Enums\AuditAction;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Users\PasswordChangeSecurity;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        // Fase 6.31: igual que la API, revoca el api_token, fija
        // password_changed_at, cierra todas las sesiones web y queda auditado
        // (sin actor: es el flujo por correo, sin sesión).
        $user->forceFill([
            'password' => $input['password'],
            'must_change_password' => false,
            ...PasswordChangeSecurity::fields(),
        ])->save();

        PasswordChangeSecurity::terminateWebSessions($user);
        AuditLogger::record($user, AuditAction::PasswordChanged, request(), ['source' => 'web_password_reset']);
    }
}
