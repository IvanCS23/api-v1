<?php

namespace App\Policies;

use App\Models\QuoteItem;
use App\Models\User;

/**
 * Autorización por empresa para QuoteItem. Ver SaleItemPolicy para el
 * razonamiento completo.
 */
class QuoteItemPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, QuoteItem $quoteItem): bool
    {
        return $user->company_id === $quoteItem->company_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function delete(User $user, QuoteItem $quoteItem): bool
    {
        return $user->company_id === $quoteItem->company_id;
    }
}
