<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Users\RoleService;

/**
 * Autorización por empresa para InvoiceItem. Solo viewAny/view: las
 * líneas de una Invoice nunca se crean/editan/eliminan manualmente
 * (siempre las genera SaleToInvoiceConverter), así que no existen
 * métodos create/update/delete aquí.
 */
class InvoiceItemPolicy
{
    public function __construct(private readonly RoleService $roles) {}

    public function viewAny(User $user): bool
    {
        return $this->roles->allows($user, Permission::InvoicesView);
    }

    public function view(User $user, InvoiceItem $invoiceItem): bool
    {
        return $user->company_id === $invoiceItem->company_id
            && $this->roles->allows($user, Permission::InvoicesView);
    }
}
