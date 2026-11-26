<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Users\RoleService;

/**
 * Autorización por empresa para SaleItem. En la práctica, las acciones
 * de mutación (agregar/eliminar líneas) ya se autorizan sobre la Sale
 * padre en el controller; esta policy es una segunda capa de defensa,
 * igual que ClientPolicy/ProductPolicy/EmployePolicy.
 */
class SaleItemPolicy
{
    public function __construct(private readonly RoleService $roles) {}

    public function viewAny(User $user): bool
    {
        return $this->roles->allows($user, Permission::SalesView);
    }

    public function view(User $user, SaleItem $saleItem): bool
    {
        return $user->company_id === $saleItem->company_id
            && $this->roles->allows($user, Permission::SalesView);
    }

    public function create(User $user): bool
    {
        return $this->roles->allows($user, Permission::SalesManage);
    }

    public function update(User $user, SaleItem $saleItem): bool
    {
        return $user->company_id === $saleItem->company_id
            && $this->roles->allows($user, Permission::SalesManage);
    }

    public function delete(User $user, SaleItem $saleItem): bool
    {
        return $user->company_id === $saleItem->company_id
            && $this->roles->allows($user, Permission::SalesManage);
    }
}
