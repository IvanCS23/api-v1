<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Product;
use App\Models\User;
use App\Services\Users\RoleService;

/**
 * Autorización por empresa para Product. Ver ClientPolicy para el
 * razonamiento completo (defense in depth sobre el Global Scope).
 */
class ProductPolicy
{
    public function __construct(private readonly RoleService $roles) {}

    public function viewAny(User $user): bool
    {
        return $this->roles->allows($user, Permission::ProductsView);
    }

    public function view(User $user, Product $product): bool
    {
        return $user->company_id === $product->company_id
            && $this->roles->allows($user, Permission::ProductsView);
    }

    public function create(User $user): bool
    {
        return $this->roles->allows($user, Permission::ProductsManage);
    }

    public function update(User $user, Product $product): bool
    {
        return $user->company_id === $product->company_id
            && $this->roles->allows($user, Permission::ProductsManage);
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->company_id === $product->company_id
            && $this->roles->allows($user, Permission::ProductsManage);
    }
}
