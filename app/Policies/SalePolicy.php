<?php

namespace App\Policies;

use App\Models\Sale;
use App\Models\User;

/**
 * Autorización por empresa para Sale. Ver ClientPolicy para el
 * razonamiento completo (defense in depth sobre el Global Scope).
 */
class SalePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Sale $sale): bool
    {
        return $user->company_id === $sale->company_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Sale $sale): bool
    {
        return $user->company_id === $sale->company_id;
    }

    public function delete(User $user, Sale $sale): bool
    {
        return $user->company_id === $sale->company_id;
    }
}
