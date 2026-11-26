<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Payment;
use App\Models\User;
use App\Services\Users\RoleService;

/**
 * Fase 6.35 (D5): los pagos y sus REP sólo los manejan Admin y Contador de
 * la misma empresa. Ventas y Empleado no los ven. El estado (sólo borradores
 * se editan o eliminan) lo revisa el controlador con un 409 explícito.
 *
 * Además se exige el permiso del rol (RoleService): payments.view para
 * consultar y payments.manage para todo lo demás.
 */
class PaymentPolicy
{
    public function __construct(private readonly RoleService $roles) {}

    public function viewAny(User $user): bool
    {
        return $this->canManage($user)
            && $this->roles->allows($user, Permission::PaymentsView);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $this->sameCompany($user, $payment)
            && $this->roles->allows($user, Permission::PaymentsView);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user)
            && $this->roles->allows($user, Permission::PaymentsManage);
    }

    public function update(User $user, Payment $payment): bool
    {
        return $this->sameCompany($user, $payment)
            && $this->roles->allows($user, Permission::PaymentsManage);
    }

    public function delete(User $user, Payment $payment): bool
    {
        return $this->sameCompany($user, $payment)
            && $this->roles->allows($user, Permission::PaymentsManage);
    }

    private function sameCompany(User $user, Payment $payment): bool
    {
        return $this->canManage($user) && $user->company_id === $payment->company_id;
    }

    private function canManage(User $user): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Accountant], true);
    }
}
