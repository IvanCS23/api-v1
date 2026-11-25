<?php

namespace App\Services\Users;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\CannotDeactivateSelfException;
use App\Exceptions\CompanyAdminViolationException;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * Lógica de negocio de usuarios de la empresa: listado, alta, edición
 * (con protección de administradores, invalidación de tokens y avatar) y
 * desactivación.
 *
 * La autorización (policies) NO se hace aquí: es responsabilidad de la
 * capa HTTP. El Request solo se recibe donde AuditLogger lo necesita
 * como contexto de la auditoría.
 */
class UserService
{
    public const DEFAULT_PER_PAGE = 15;

    public function __construct(private readonly CompanyAdminGuard $admins) {}

    /**
     * @param  array{search?: string|null, role?: string|null, status?: string|null, per_page?: int|string|null}  $filters  Datos validados.
     */
    public function paginatedForCompany(int|string $companyId, array $filters): LengthAwarePaginator
    {
        $query = User::query()
            ->with('company')
            ->where('company_id', $companyId);

        if ($search = $filters['search'] ?? null) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($role = $filters['role'] ?? null) {
            $query->where('role', $role);
        }

        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }

        return $query
            ->orderByDesc('created_at')
            ->paginate((int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE))
            ->withQueryString();
    }

    /**
     * @throws ModelNotFoundException
     */
    public function findWithinCompany(int|string $companyId, int|string $id): User
    {
        return User::query()
            ->where('company_id', $companyId)
            ->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $validated  Datos validados por StoreUserRequest.
     */
    public function create(array $validated, int|string $companyId, ?UploadedFile $avatar = null): User
    {
        $user = new User;
        $user->fill([
            'company_id' => $companyId,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'status' => $validated['status'] ?? UserStatus::Active->value,
            'phone' => $validated['phone'] ?? null,
            'position' => $validated['position'] ?? null,
            'branch' => $validated['branch'] ?? null,
            'must_change_password' => $validated['must_change_password'] ?? false,
            'password_changed_at' => now(),
        ]);

        if ($avatar !== null) {
            $user->avatar = $avatar->store('avatars', 'public');
        }

        $user->save();

        return $user->load('company');
    }

    /**
     * @param  array<string, mixed>  $validated  Datos validados por UpdateUserRequest.
     *
     * @throws CompanyAdminViolationException
     */
    public function update(User $actor, User $user, array $validated, ?UploadedFile $avatar, Request $request): User
    {
        $previousStatus = $user->status;

        $violation = $this->admins->change(
            $actor,
            $user,
            isset($validated['role']) ? UserRole::from($validated['role']) : null,
            isset($validated['status']) ? UserStatus::from($validated['status']) : null,
            function () use ($user, $validated, $avatar): void {
                $previousEmail = $user->email;
                $user->fill(collect($validated)->except('avatar')->toArray());

                // Fase 6.32: un enlace de recuperación enviado al correo
                // anterior deja de servir.
                if ($user->isDirty('email')) {
                    PasswordChangeSecurity::forgetResetTokensFor($previousEmail);
                }

                if ($avatar !== null) {
                    if ($user->avatar) {
                        Storage::disk('public')->delete($user->avatar);
                    }
                    $user->avatar = $avatar->store('avatars', 'public');
                }

                $user->save();
            },
        );

        if ($violation !== null) {
            throw new CompanyAdminViolationException($violation);
        }

        if (array_key_exists('status', $validated) && $user->status !== $previousStatus) {
            $action = $user->status === UserStatus::Active ? AuditAction::Activated : AuditAction::Deactivated;
            AuditLogger::record($user, $action, $request);
        }

        return $user->load('company');
    }

    /**
     * Desactiva (no borra) al usuario.
     *
     * @throws CannotDeactivateSelfException
     * @throws CompanyAdminViolationException
     */
    public function deactivate(User $actor, User $user, Request $request): void
    {
        if ($user->id === $actor->id) {
            throw new CannotDeactivateSelfException;
        }

        $violation = $this->admins->change(
            $actor,
            $user,
            null,
            UserStatus::Inactive,
            fn () => $user->update(['status' => UserStatus::Inactive]),
        );

        if ($violation !== null) {
            throw new CompanyAdminViolationException($violation);
        }

        AuditLogger::record($user, AuditAction::Deactivated, $request);
    }
}
