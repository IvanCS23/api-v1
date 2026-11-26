<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Quote;
use App\Models\User;
use App\Services\Users\RoleService;

/**
 * Autorización por empresa para Quote. Ver ClientPolicy para el
 * razonamiento completo (defense in depth sobre el Global Scope).
 */
class QuotePolicy
{
    public function __construct(private readonly RoleService $roles) {}

    public function viewAny(User $user): bool
    {
        return $this->roles->allows($user, Permission::QuotesView);
    }

    public function view(User $user, Quote $quote): bool
    {
        return $user->company_id === $quote->company_id
            && $this->roles->allows($user, Permission::QuotesView);
    }

    public function create(User $user): bool
    {
        return $this->roles->allows($user, Permission::QuotesManage);
    }

    public function update(User $user, Quote $quote): bool
    {
        return $user->company_id === $quote->company_id
            && $this->roles->allows($user, Permission::QuotesManage);
    }

    public function delete(User $user, Quote $quote): bool
    {
        return $user->company_id === $quote->company_id
            && $this->roles->allows($user, Permission::QuotesManage);
    }
}
