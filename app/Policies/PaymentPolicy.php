<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Payment;
use App\Models\User;

/**
 * Fase 6.35 (D5): los pagos y sus REP sólo los manejan Admin y Contador de
 * la misma empresa. Ventas y Empleado no los ven. El estado (sólo borradores
 * se editan o eliminan) lo revisa el controlador con un 409 explícito.
 */
class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $this->sameCompany($user, $payment);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Payment $payment): bool
    {
        return $this->sameCompany($user, $payment);
    }

    public function delete(User $user, Payment $payment): bool
    {
        return $this->sameCompany($user, $payment);
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
