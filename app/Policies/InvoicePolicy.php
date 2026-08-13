<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\User;

/**
 * Autorización por empresa para Invoice. Ver SalePolicy/QuotePolicy para
 * el razonamiento completo (defense in depth sobre el Global Scope).
 */
class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $user->company_id === $invoice->company_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $user->company_id === $invoice->company_id;
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return $user->company_id === $invoice->company_id;
    }

    public function reconcilePac(User $user, Invoice $invoice): bool
    {
        return $this->canManagePac($user, $invoice);
    }

    public function issuePac(User $user, Invoice $invoice): bool
    {
        return $this->canManagePac($user, $invoice);
    }

    public function managePacArtifacts(User $user, Invoice $invoice): bool
    {
        return $this->canManagePac($user, $invoice);
    }

    public function cancelPac(User $user, Invoice $invoice): bool
    {
        return $this->canManagePac($user, $invoice);
    }

    private function canManagePac(User $user, Invoice $invoice): bool
    {
        return $user->company_id === $invoice->company_id
            && in_array($user->role, [UserRole::Admin, UserRole::Accountant], true);
    }
}
