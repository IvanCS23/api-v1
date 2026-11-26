<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Employe;
use App\Models\User;
use App\Services\Users\RoleService;

/**
 * Autorización por empresa para Employe. Ver ClientPolicy para el
 * razonamiento completo (defense in depth sobre el Global Scope).
 */
class EmployePolicy
{
    public function __construct(private readonly RoleService $roles) {}

    public function viewAny(User $user): bool
    {
        return $this->roles->allows($user, Permission::EmployeesView);
    }

    public function view(User $user, Employe $employe): bool
    {
        return $user->company_id === $employe->company_id
            && $this->roles->allows($user, Permission::EmployeesView);
    }

    public function create(User $user): bool
    {
        return $this->roles->allows($user, Permission::EmployeesManage);
    }

    public function update(User $user, Employe $employe): bool
    {
        return $user->company_id === $employe->company_id
            && $this->roles->allows($user, Permission::EmployeesManage);
    }

    public function delete(User $user, Employe $employe): bool
    {
        return $user->company_id === $employe->company_id
            && $this->roles->allows($user, Permission::EmployeesManage);
    }
}
