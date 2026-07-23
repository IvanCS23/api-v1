<?php

namespace App\Policies;

use App\Models\Employe;
use App\Models\User;

/**
 * Autorización por empresa para Employe. Ver ClientPolicy para el
 * razonamiento completo (defense in depth sobre el Global Scope).
 */
class EmployePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Employe $employe): bool
    {
        return $user->company_id === $employe->company_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Employe $employe): bool
    {
        return $user->company_id === $employe->company_id;
    }

    public function delete(User $user, Employe $employe): bool
    {
        return $user->company_id === $employe->company_id;
    }
}
