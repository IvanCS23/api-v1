<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeRequest;
use App\Http\Requests\UpdateEmployeRequest;
use App\Http\Resources\EmployeResource;
use App\Models\Employe;

class EmployeController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Employe::class);

        return EmployeResource::collection(Employe::all());
    }

    public function store(StoreEmployeRequest $request)
    {
        $this->authorize('create', Employe::class);

        $employe = Employe::create($request->validated());

        return (new EmployeResource($employe))->response()->setStatusCode(201);
    }

    public function show(string $id)
    {
        $employe = Employe::findOrFail($id);

        $this->authorize('view', $employe);

        return new EmployeResource($employe);
    }

    public function update(UpdateEmployeRequest $request, string $id)
    {
        $employe = Employe::findOrFail($id);

        $this->authorize('update', $employe);

        $employe->update($request->validated());

        return new EmployeResource($employe);
    }

    public function destroy(string $id)
    {
        $employe = Employe::findOrFail($id);

        $this->authorize('delete', $employe);

        $employe->delete();

        return response()->json(['message' => 'Empleado eliminado correctamente']);
    }
}
