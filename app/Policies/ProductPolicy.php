<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

/**
 * Autorización por empresa para Product. Ver ClientPolicy para el
 * razonamiento completo (defense in depth sobre el Global Scope).
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Product $product): bool
    {
        return $user->company_id === $product->company_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Product $product): bool
    {
        return $user->company_id === $product->company_id;
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->company_id === $product->company_id;
    }
}
