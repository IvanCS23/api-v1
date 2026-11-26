<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Client;
use App\Models\User;
use App\Services\Users\RoleService;

/**
 * Autorización por empresa para Client.
 *
 * El Global Scope (CompanyScope, vía BelongsToCompany) ya impide que un
 * usuario vea registros de otra empresa en las consultas normales; esta
 * policy es una segunda capa de defensa (defense in depth) para el caso
 * en que una consulta explícita use withoutGlobalScope(), y es el punto
 * de extensión natural para reglas de rol (UserRole) el día que se
 * necesiten — hoy solo se exige pertenecer a la misma empresa.
 */
class ClientPolicy
{
    public function __construct(private readonly RoleService $roles) {}

    public function viewAny(User $user): bool
    {
        return $this->roles->allows($user, Permission::ClientsView);
    }

    public function view(User $user, Client $client): bool
    {
        return $user->company_id === $client->company_id
            && $this->roles->allows($user, Permission::ClientsView);
    }

    public function create(User $user): bool
    {
        return $this->roles->allows($user, Permission::ClientsManage);
    }

    public function update(User $user, Client $client): bool
    {
        return $user->company_id === $client->company_id
            && $this->roles->allows($user, Permission::ClientsManage);
    }

    public function delete(User $user, Client $client): bool
    {
        return $user->company_id === $client->company_id
            && $this->roles->allows($user, Permission::ClientsManage);
    }
}
