<?php

namespace App\Policies;

use App\Models\SaleItem;
use App\Models\User;

/**
 * Autorización por empresa para SaleItem. En la práctica, las acciones
 * de mutación (agregar/eliminar líneas) ya se autorizan sobre la Sale
 * padre en el controller; esta policy es una segunda capa de defensa,
 * igual que ClientPolicy/ProductPolicy/EmployePolicy.
 */
class SaleItemPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SaleItem $saleItem): bool
    {
        return $user->company_id === $saleItem->company_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function delete(User $user, SaleItem $saleItem): bool
    {
        return $user->company_id === $saleItem->company_id;
    }
}
