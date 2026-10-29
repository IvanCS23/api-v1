<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\User;

/**
 * Fase 6.27b — Datos fiscales y CSD de la empresa del usuario autenticado.
 * Consultar: Admin o Accountant. Modificar (datos fiscales, CSD): sólo
 * Admin. Siempre la propia empresa: la organización de Facturapi sale de
 * su fila, nunca de la petición.
 */
class CompanyPolicy
{
    public function viewFiscal(User $user, Company $company): bool
    {
        return $this->sameActiveCompany($user, $company)
            && in_array($user->role, [UserRole::Admin, UserRole::Accountant], true);
    }

    public function updateFiscal(User $user, Company $company): bool
    {
        return $this->sameActiveCompany($user, $company) && $user->role === UserRole::Admin;
    }

    private function sameActiveCompany(User $user, Company $company): bool
    {
        return $user->company_id === $company->id && $user->status === UserStatus::Active;
    }
}
