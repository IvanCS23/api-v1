<?php

namespace App\Services\Users;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Fase 6.27b (0): reglas sobre administradores al editar o desactivar
 * usuarios. Se evalúan en este orden:
 *
 * 1. La empresa conserva al menos un Admin activo (`USER_LAST_ADMIN`).
 * 2. Nadie cambia su propio rol ni su propio estado.
 * 3. Nadie cambia el rol de otro Admin.
 *
 * La revisión y la escritura ocurren en la misma transacción, con los
 * Admin activos de la empresa bloqueados: dos administradores que se
 * desactivan entre sí al mismo tiempo no pueden dejarla sin ninguno.
 */
final class CompanyAdminGuard
{
    /**
     * Ejecuta $apply sólo si el cambio está permitido.
     *
     * @param  Closure(): void  $apply
     * @return array{code: string, message: string}|null La violación, o null si se aplicó.
     */
    public function change(User $actor, User $target, ?UserRole $role, ?UserStatus $status, Closure $apply): ?array
    {
        return DB::transaction(function () use ($actor, $target, $role, $status, $apply): ?array {
            $activeAdminIds = User::query()
                ->where('company_id', $target->company_id)
                ->where('role', UserRole::Admin->value)
                ->where('status', UserStatus::Active->value)
                ->lockForUpdate()
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            // Rol y estado vigentes, no los que se leyeron antes del bloqueo.
            $target->refresh();
            $violation = $this->violation($actor, $target, $role, $status, $activeAdminIds);

            if ($violation === null) {
                $apply();
            }

            return $violation;
        });
    }

    /**
     * Un Admin no cambia ni restablece la contraseña de otro Admin: el
     * restablecimiento devuelve la contraseña temporal y equivaldría a
     * tomar su cuenta.
     *
     * @return array{code: string, message: string}|null
     */
    public function passwordViolation(User $actor, User $target): ?array
    {
        if ($actor->id !== $target->id && $target->role === UserRole::Admin) {
            return $this->error('USER_ADMIN_PASSWORD_LOCKED', 'No puedes cambiar ni restablecer la contraseña de otro administrador.');
        }

        return null;
    }

    /**
     * @param  list<int>  $activeAdminIds
     * @return array{code: string, message: string}|null
     */
    private function violation(User $actor, User $target, ?UserRole $role, ?UserStatus $status, array $activeAdminIds): ?array
    {
        $roleChanges = $role !== null && $role !== $target->role;
        $statusChanges = $status !== null && $status !== $target->status;
        $wasActiveAdmin = in_array($target->id, $activeAdminIds, true);
        $staysActiveAdmin = ($role ?? $target->role) === UserRole::Admin && ($status ?? $target->status) === UserStatus::Active;

        if ($wasActiveAdmin && ! $staysActiveAdmin && array_diff($activeAdminIds, [$target->id]) === []) {
            return $this->error('USER_LAST_ADMIN', 'La empresa debe conservar al menos un administrador activo.');
        }

        if ($actor->id === $target->id && $roleChanges) {
            return $this->error('USER_SELF_ROLE_CHANGE', 'No puedes cambiar tu propio rol.');
        }

        if ($actor->id === $target->id && $statusChanges) {
            return $this->error('USER_SELF_STATUS_CHANGE', 'No puedes cambiar tu propio estado.');
        }

        if ($roleChanges && $target->role === UserRole::Admin) {
            return $this->error('USER_ADMIN_ROLE_LOCKED', 'No puedes cambiar el rol de otro administrador.');
        }

        return null;
    }

    /** @return array{code: string, message: string} */
    private function error(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }
}
