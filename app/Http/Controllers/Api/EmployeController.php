<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeRequest;
use App\Http\Requests\UpdateEmployeRequest;
use App\Http\Resources\EmployeResource;
use App\Models\Employe;
use App\Services\Catalogs\EmployeService;
use Illuminate\Http\Request;

class EmployeController extends Controller
{
    public function __construct(private readonly EmployeService $employes) {}

    /**
     * Sin ?page devuelve todos (mismo formato que antes).
     * Con ?page=N[&per_page=M] devuelve la respuesta paginada (incluye meta y links).
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Employe::class);

        if ($request->has('page')) {
            return EmployeResource::collection(
                $this->employes->paginated(
                    (int) $request->query('page', 1),
                    (int) $request->query('per_page', EmployeService::DEFAULT_PER_PAGE),
                )
            );
        }

        return EmployeResource::collection($this->employes->all());
    }

    public function store(StoreEmployeRequest $request)
    {
        $this->authorize('create', Employe::class);

        $employe = $this->employes->create($request->validated());

        return (new EmployeResource($employe))->response()->setStatusCode(201);
    }

    public function show(string $id)
    {
        $employe = $this->employes->get($id);

        $this->authorize('view', $employe);

        return new EmployeResource($employe);
    }

    public function update(UpdateEmployeRequest $request, string $id)
    {
        $employe = $this->employes->get($id);

        $this->authorize('update', $employe);

        $employe = $this->employes->update($employe, $request->validated());

        return new EmployeResource($employe);
    }

    public function destroy(string $id)
    {
        $employe = $this->employes->get($id);

        $this->authorize('delete', $employe);

        $this->employes->delete($employe);

        return response()->json(['message' => 'Empleado eliminado correctamente']);
    }
}
