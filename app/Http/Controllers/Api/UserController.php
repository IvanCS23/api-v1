<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\CannotDeactivateSelfException;
use App\Exceptions\CompanyAdminViolationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Users\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rules\Enum;

class UserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', new Enum(UserRole::class)],
            'status' => ['nullable', new Enum(UserStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $users = $this->users->paginatedForCompany($request->user('api')->company_id, $filters);

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
        $this->authorize('create', User::class);

        $user = $this->users->create(
            $request->validated(),
            $request->user('api')->company_id,
            $this->avatarFrom($request),
        );

        return response()->json(['user' => new UserResource($user)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $this->findWithinCompany($request, $id);

        return response()->json(['user' => new UserResource($user->load('company'))]);
    }

    public function update(UpdateUserRequest $request, string $id): JsonResponse
    {
        $user = $this->findWithinCompany($request, $id);
        $this->authorize('update', $user);

        try {
            $user = $this->users->update(
                $request->user('api'),
                $user,
                $request->validated(),
                $this->avatarFrom($request),
                $request,
            );
        } catch (CompanyAdminViolationException $e) {
            return response()->json($e->violation, 422);
        }

        return response()->json(['user' => new UserResource($user)]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $this->findWithinCompany($request, $id);
        $this->authorize('delete', $user);

        try {
            $this->users->deactivate($request->user('api'), $user, $request);
        } catch (CannotDeactivateSelfException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (CompanyAdminViolationException $e) {
            return response()->json($e->violation, 422);
        }

        return response()->json(['message' => 'Usuario desactivado correctamente.']);
    }

    private function findWithinCompany(Request $request, string $id): User
    {
        return $this->users->findWithinCompany($request->user('api')->company_id, $id);
    }

    /**
     * Igual que antes: solo se toma el avatar si es un archivo subido válido.
     */
    private function avatarFrom(Request $request): ?UploadedFile
    {
        return $request->hasFile('avatar') ? $request->file('avatar') : null;
    }
}
