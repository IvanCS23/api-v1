<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;

/**
 * Fase 6.27b (0): la gestión de usuarios es sólo para administradores
 * activos de la misma empresa. Antes cualquier usuario autenticado podía
 * crear un Admin o restablecer la contraseña de uno (la respuesta incluye
 * la contraseña temporal). Las reglas sobre roles de administradores y el
 * último Admin están en CompanyAdminGuard.
 *
 * El estado se revisa aquí porque un usuario desactivado conserva su token.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->company_id === $user->company_id;
    }

    public function create(User $actor): bool
    {
        return $this->isActiveAdmin($actor);
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->company_id === $user->company_id && $this->isActiveAdmin($actor);
    }

    public function delete(User $actor, User $user): bool
    {
        return $actor->company_id === $user->company_id && $this->isActiveAdmin($actor);
    }

    /** Cambiar o restablecer contraseña, cerrar sesiones y forzar cambio de contraseña. */
    public function manageSecurity(User $actor, User $user): bool
    {
        return $actor->company_id === $user->company_id && $this->isActiveAdmin($actor);
    }

    private function isActiveAdmin(User $user): bool
    {
        return $user->role === UserRole::Admin && $user->status === UserStatus::Active;
    }
}
