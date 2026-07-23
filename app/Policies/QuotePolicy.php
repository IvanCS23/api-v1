<?php

namespace App\Policies;

use App\Models\Quote;
use App\Models\User;

/**
 * Autorización por empresa para Quote. Ver ClientPolicy para el
 * razonamiento completo (defense in depth sobre el Global Scope).
 */
class QuotePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Quote $quote): bool
    {
        return $user->company_id === $quote->company_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Quote $quote): bool
    {
        return $user->company_id === $quote->company_id;
    }

    public function delete(User $user, Quote $quote): bool
    {
        return $user->company_id === $quote->company_id;
    }
}
