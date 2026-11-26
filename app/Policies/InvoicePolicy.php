<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Users\RoleService;

/**
 * Autorización por empresa para Invoice. Ver SalePolicy/QuotePolicy para
 * el razonamiento completo (defense in depth sobre el Global Scope).
 *
 * Además de las condiciones de empresa y de rol de cada acción, se exige el
 * permiso del rol (RoleService): invoices.view para consultar e
 * invoices.manage para todo lo demás.
 */
class InvoicePolicy
{
    public function __construct(private readonly RoleService $roles) {}

    public function viewAny(User $user): bool
    {
        return $this->roles->allows($user, Permission::InvoicesView);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $user->company_id === $invoice->company_id
            && $this->roles->allows($user, Permission::InvoicesView);
    }

    public function create(User $user): bool
    {
        return $this->roles->allows($user, Permission::InvoicesManage);
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $user->company_id === $invoice->company_id
            && $this->roles->allows($user, Permission::InvoicesManage);
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return $user->company_id === $invoice->company_id
            && $this->roles->allows($user, Permission::InvoicesManage);
    }

    public function reconcilePac(User $user, Invoice $invoice): bool
    {
        return $this->canManagePac($user, $invoice)
            && $this->roles->allows($user, Permission::InvoicesManage);
    }

    public function issuePac(User $user, Invoice $invoice): bool
    {
        return $this->canManagePac($user, $invoice)
            && $this->roles->allows($user, Permission::InvoicesManage);
    }

    public function issueBusiness(User $user, Invoice $invoice): bool
    {
        return $this->canManagePac($user, $invoice)
            && $this->roles->allows($user, Permission::InvoicesManage);
    }

    public function cancelBusiness(User $user, Invoice $invoice): bool
    {
        if ($user->company_id !== $invoice->company_id) {
            return false;
        }

        $hasAnyFiscalIdentity = filled($invoice->pac_external_id) || filled($invoice->cfdi_uuid);

        return (! $hasAnyFiscalIdentity || $this->canManagePac($user, $invoice))
            && $this->roles->allows($user, Permission::InvoicesManage);
    }

    public function managePacArtifacts(User $user, Invoice $invoice): bool
    {
        return $this->canManagePac($user, $invoice)
            && $this->roles->allows($user, Permission::InvoicesManage);
    }

    public function cancelPac(User $user, Invoice $invoice): bool
    {
        return $this->canManagePac($user, $invoice)
            && $this->roles->allows($user, Permission::InvoicesManage);
    }

    /** Fase 6.30: enviar el CFDI por correo no cambia el estado fiscal; Ventas también. */
    public function sendEmail(User $user, Invoice $invoice): bool
    {
        return $user->company_id === $invoice->company_id
            && in_array($user->role, [UserRole::Admin, UserRole::Accountant, UserRole::Sales], true)
            && $this->roles->allows($user, Permission::InvoicesManage);
    }

    /** Fase 6.30: sólo Admin y Contador agregan o quitan destinatarios; Ventas usa los del cliente. */
    public function editEmailRecipients(User $user, Invoice $invoice): bool
    {
        return $this->canManagePac($user, $invoice)
            && $this->roles->allows($user, Permission::InvoicesManage);
    }

    private function canManagePac(User $user, Invoice $invoice): bool
    {
        return $user->company_id === $invoice->company_id
            && in_array($user->role, [UserRole::Admin, UserRole::Accountant], true);
    }
}
