<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Sale;
use App\Models\User;
use App\Services\Users\RoleService;

/**
 * Autorización por empresa para Sale. Ver ClientPolicy para el
 * razonamiento completo (defense in depth sobre el Global Scope).
 */
class SalePolicy
{
    public function __construct(private readonly RoleService $roles) {}

    public function viewAny(User $user): bool
    {
        return $this->roles->allows($user, Permission::SalesView);
    }

    public function view(User $user, Sale $sale): bool
    {
        return $user->company_id === $sale->company_id
            && $this->roles->allows($user, Permission::SalesView);
    }

    public function create(User $user): bool
    {
        return $this->roles->allows($user, Permission::SalesManage);
    }

    public function update(User $user, Sale $sale): bool
    {
        return $user->company_id === $sale->company_id
            && $this->roles->allows($user, Permission::SalesManage);
    }

    public function delete(User $user, Sale $sale): bool
    {
        return $user->company_id === $sale->company_id
            && $this->roles->allows($user, Permission::SalesManage);
    }
}
