<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\QuoteItem;
use App\Models\User;
use App\Services\Users\RoleService;

/**
 * Autorización por empresa para QuoteItem. Ver SaleItemPolicy para el
 * razonamiento completo.
 */
class QuoteItemPolicy
{
    public function __construct(private readonly RoleService $roles) {}

    public function viewAny(User $user): bool
    {
        return $this->roles->allows($user, Permission::QuotesView);
    }

    public function view(User $user, QuoteItem $quoteItem): bool
    {
        return $user->company_id === $quoteItem->company_id
            && $this->roles->allows($user, Permission::QuotesView);
    }

    public function create(User $user): bool
    {
        return $this->roles->allows($user, Permission::QuotesManage);
    }

    public function update(User $user, QuoteItem $quoteItem): bool
    {
        return $user->company_id === $quoteItem->company_id
            && $this->roles->allows($user, Permission::QuotesManage);
    }

    public function delete(User $user, QuoteItem $quoteItem): bool
    {
        return $user->company_id === $quoteItem->company_id
            && $this->roles->allows($user, Permission::QuotesManage);
    }
}
