<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Users\RoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function __construct(private readonly RoleService $roles) {}
    
    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->roles->catalog()]);
    }

    /**
     * GET /api/roles/me: rol y permisos del usuario autenticado, para que el
     * frontend oculte botones que el usuario no puede usar.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user('api');

        return response()->json([
            'role' => $user->role->value,
            'label' => $user->role->label(),
            'permissions' => array_map(
                fn ($permission): string => $permission->value,
                $this->roles->permissionsFor($user->role),
            ),
        ]);
    }
}
