<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Enum;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', new Enum(UserRole::class)],
            'status' => ['nullable', new Enum(UserStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $authUser = $request->user('api');

        $query = User::query()
            ->with('company')
            ->where('company_id', $authUser->company_id);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($role = $request->query('role')) {
            $query->where('role', $role);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $users = $query
            ->orderByDesc('created_at')
            ->paginate((int) $request->query('per_page', 15))
            ->withQueryString();

        return response()->json([
            'data' => UserResource::collection($users->items()),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $authUser = $request->user('api');

        $user = new User();
        $user->fill([
            'company_id' => $authUser->company_id,
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

        if ($request->hasFile('avatar')) {
            $user->avatar = $request->file('avatar')->store('avatars', 'public');
        }

        $user->save();

        return response()->json(['user' => new UserResource($user->load('company'))], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $this->findWithinCompany($request, $id);

        return response()->json(['user' => new UserResource($user->load('company'))]);
    }

    public function update(UpdateUserRequest $request, string $id): JsonResponse
    {
        $user = $this->findWithinCompany($request, $id);
        $validated = $request->validated();
        $previousStatus = $user->status;

        $user->fill(collect($validated)->except('avatar')->toArray());

        if ($request->hasFile('avatar')) {
            if ($user->avatar) {
                Storage::disk('public')->delete($user->avatar);
            }
            $user->avatar = $request->file('avatar')->store('avatars', 'public');
        }

        $user->save();

        if (array_key_exists('status', $validated) && $user->status !== $previousStatus) {
            $action = $user->status === UserStatus::Active ? AuditAction::Activated : AuditAction::Deactivated;
            AuditLogger::record($user, $action, $request);
        }

        return response()->json(['user' => new UserResource($user->load('company'))]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $this->findWithinCompany($request, $id);

        if ($user->id === $request->user('api')->id) {
            return response()->json([
                'message' => 'No puedes desactivar tu propia cuenta.',
            ], 422);
        }

        $user->update(['status' => UserStatus::Inactive]);

        AuditLogger::record($user, AuditAction::Deactivated, $request);

        return response()->json(['message' => 'Usuario desactivado correctamente.']);
    }

    private function findWithinCompany(Request $request, string $id): User
    {
        return User::query()
            ->where('company_id', $request->user('api')->company_id)
            ->findOrFail($id);
    }
}
