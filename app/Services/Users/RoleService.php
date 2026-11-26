<?php

namespace App\Services\Users;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\User;

/**
 * - Admin: todos los permisos.
 * - Accountant: lo mismo que puede hacer hoy (todo, salvo administrar
 *   usuarios, que UserPolicy ya reserva al Admin).
 * - Sales y Employee: lo mismo que Accountant, sin pagos.
 * - Client: solo consulta (`*.view`), sin pagos.
 *
 * Pagos (payments.*) solo para Admin y Accountant, igual que PaymentPolicy
 * (Fase 6.35, D5), para que el catálogo coincida con lo que la API permite.
 */
final class RoleService
{
    private const PAYMENT_PERMISSIONS = [Permission::PaymentsView, Permission::PaymentsManage];

    /**
     * @return list<Permission>
     */
    public function permissionsFor(UserRole $role): array
    {
        // Sin `default` a propósito: un rol nuevo sin permisos definidos
        // lanza UnhandledMatchError y la prueba lo detecta.
        return match ($role) {
            UserRole::Admin => Permission::cases(),
            UserRole::Accountant => $this->operationalPermissions(),
            UserRole::Sales,
            UserRole::Employee => $this->withoutPayments($this->operationalPermissions()),
            UserRole::Client => $this->withoutPayments($this->viewOnlyPermissions()),
        };
    }

    public function roleAllows(UserRole $role, Permission $permission): bool
    {
        return in_array($permission, $this->permissionsFor($role), true);
    }

    /**
     * No revisa el estado (activo/inactivo) para no cambiar el
     * comportamiento actual de las policies; UserPolicy ya lo revisa donde
     * corresponde.
     *
     * Un usuario sin rol (null) no tiene ningún permiso.
     */
    public function allows(User $user, Permission $permission): bool
    {
        return $user->role instanceof UserRole
            && $this->roleAllows($user->role, $permission);
    }

    /**
     * Catálogo de roles para el frontend (selects, pantallas de permisos).
     *
     * @return list<array{value: string, label: string, permissions: list<string>}>
     */
    public function catalog(): array
    {
        return array_map(fn (UserRole $role): array => [
            'value' => $role->value,
            'label' => $role->label(),
            'permissions' => array_map(
                fn (Permission $permission): string => $permission->value,
                $this->permissionsFor($role),
            ),
        ], UserRole::cases());
    }

    /**
     * @return list<Permission>
     */
    private function operationalPermissions(): array
    {
        return array_values(array_filter(
            Permission::cases(),
            fn (Permission $permission): bool => $permission !== Permission::UsersManage,
        ));
    }

    /**
     * @return list<Permission>
     */
    private function viewOnlyPermissions(): array
    {
        return array_values(array_filter(
            Permission::cases(),
            fn (Permission $permission): bool => $permission->isView(),
        ));
    }

    /**
     * @param  list<Permission>  $permissions
     * @return list<Permission>
     */
    private function withoutPayments(array $permissions): array
    {
        return array_values(array_filter(
            $permissions,
            fn (Permission $permission): bool => ! in_array($permission, self::PAYMENT_PERMISSIONS, true),
        ));
    }
}
