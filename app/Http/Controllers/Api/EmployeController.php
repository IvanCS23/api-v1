<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employe;
use Illuminate\Http\Request;

class EmployeController extends Controller
{
    public function index()
    {
        return response()->json(Employe::all());
    }

    public function store(Request $request)
    {
        $this->normalizeInput($request);

        $validated = $request->validate([
            'email' => 'required|email|unique:employes,email',
            'nombre' => 'required|string|max:255',
            'apellido_paterno' => 'required|string|max:255',
            'apellido_materno' => 'required|string|max:255',
            'curp' => 'required|string|max:18|unique:employes,curp',
            'rfc' => 'required|string|max:13|unique:employes,rfc',
            'calle' => 'required|string|max:255',
            'colonia' => 'required|string|max:255',
            'no_exterior' => 'required|string|max:50',
            'no_interior' => 'nullable|string|max:50',
            'codigo_postal' => 'required|string|max:5',
            'localidad' => 'required|string|max:255',
            'municipio' => 'required|string|max:255',
            'estado' => 'required|string|max:255',
            'grupo' => 'required|string|max:255',
            'sucursal' => 'required|string|max:255',
            'salario_diario' => 'required|numeric|min:0',
            'contrato' => 'required|string|max:255',
            'regimen_contratacion' => 'required|string|max:255',
            'tipo_jornada' => 'required|string|max:255',
            'banco' => 'nullable|string|max:255',
            'clave_bancaria' => 'nullable|string|max:18|unique:employes,clave_bancaria',
            'periodidad_pago' => 'required|string|max:255',
            'departamento' => 'required|string|max:255',
            'puesto' => 'required|string|max:255',
            'no_empleado' => 'required|string|max:255|unique:employes,no_empleado',
            'seguro_social' => 'required|string|max:11|unique:employes,seguro_social',
            'subcontratacion' => 'nullable|string|max:255',
        ]);

        $employe = Employe::create($validated);

        return response()->json($employe, 201);
    }

    public function show(string $id)
    {
        $employe = Employe::findOrFail($id);

        return response()->json($employe);
    }

    public function update(Request $request, string $id)
    {
        $employe = Employe::findOrFail($id);

        $this->normalizeInput($request);

        $validated = $request->validate([
            'email' => 'sometimes|email|unique:employes,email,' . $employe->id,
            'nombre' => 'sometimes|string|max:255',
            'apellido_paterno' => 'sometimes|string|max:255',
            'apellido_materno' => 'sometimes|string|max:255',
            'curp' => 'sometimes|string|max:18|unique:employes,curp,' . $employe->id,
            'rfc' => 'sometimes|string|max:13|unique:employes,rfc,' . $employe->id,
            'calle' => 'sometimes|string|max:255',
            'colonia' => 'sometimes|string|max:255',
            'no_exterior' => 'sometimes|string|max:50',
            'no_interior' => 'nullable|string|max:50',
            'codigo_postal' => 'sometimes|string|max:5',
            'localidad' => 'sometimes|string|max:255',
            'municipio' => 'sometimes|string|max:255',
            'estado' => 'sometimes|string|max:255',
            'grupo' => 'sometimes|string|max:255',
            'sucursal' => 'sometimes|string|max:255',
            'salario_diario' => 'sometimes|numeric|min:0',
            'contrato' => 'sometimes|string|max:255',
            'regimen_contratacion' => 'sometimes|string|max:255',
            'tipo_jornada' => 'sometimes|string|max:255',
            'banco' => 'nullable|string|max:255',
            'clave_bancaria' => 'nullable|string|max:18|unique:employes,clave_bancaria,' . $employe->id,
            'periodidad_pago' => 'sometimes|string|max:255',
            'departamento' => 'sometimes|string|max:255',
            'puesto' => 'sometimes|string|max:255',
            'no_empleado' => 'sometimes|string|max:255|unique:employes,no_empleado,' . $employe->id,
            'seguro_social' => 'sometimes|string|max:11|unique:employes,seguro_social,' . $employe->id,
            'subcontratacion' => 'nullable|string|max:255',
        ]);

        $employe->update($validated);

        return response()->json($employe);
    }

    public function destroy(string $id)
    {
        $employe = Employe::findOrFail($id);

        $employe->delete();

        return response()->json(['message' => 'Empleado eliminado correctamente']);
    }

    private function normalizeInput(Request $request): void
    {
        $nullableFields = [
            'no_interior',
            'banco',
            'clave_bancaria',
            'subcontratacion',
        ];

        $data = [];
        foreach ($nullableFields as $field) {
            if ($request->has($field) && $request->input($field) === '') {
                $data[$field] = null;
            }
        }

        $request->merge($data);
    }
}
