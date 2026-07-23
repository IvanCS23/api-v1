<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesEmptyStrings;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeRequest extends FormRequest
{
    use NormalizesEmptyStrings;

    /**
     * Auditoría (revisión post-Etapa D): igual que UpdateClientRequest —
     * `EmployePolicy::update()` compara `company_id`, pero es
     * inalcanzable en la práctica porque el Global Scope ya produce 404
     * antes de que la policy pueda evaluarse sobre un registro de otra
     * empresa. Ver UpdateClientRequest para el detalle completo.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyEmptyStrings(['no_interior', 'banco', 'clave_bancaria', 'subcontratacion']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(CurrentTenant::class)->id();
        $employeId = $this->route('id');

        return [
            'email' => ['sometimes', 'email', Rule::unique('employes', 'email')->where(fn ($query) => $query->where('company_id', $companyId))->ignore($employeId)],
            'nombre' => 'sometimes|string|max:255',
            'apellido_paterno' => 'sometimes|string|max:255',
            'apellido_materno' => 'sometimes|string|max:255',
            'curp' => ['sometimes', 'string', 'max:18', Rule::unique('employes', 'curp')->where(fn ($query) => $query->where('company_id', $companyId))->ignore($employeId)],
            'rfc' => ['sometimes', 'string', 'max:13', Rule::unique('employes', 'rfc')->where(fn ($query) => $query->where('company_id', $companyId))->ignore($employeId)],
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
            'clave_bancaria' => ['nullable', 'string', 'max:18', Rule::unique('employes', 'clave_bancaria')->where(fn ($query) => $query->where('company_id', $companyId))->ignore($employeId)],
            'periodidad_pago' => 'sometimes|string|max:255',
            'departamento' => 'sometimes|string|max:255',
            'puesto' => 'sometimes|string|max:255',
            'no_empleado' => ['sometimes', 'string', 'max:255', Rule::unique('employes', 'no_empleado')->where(fn ($query) => $query->where('company_id', $companyId))->ignore($employeId)],
            'seguro_social' => ['sometimes', 'string', 'max:11', Rule::unique('employes', 'seguro_social')->where(fn ($query) => $query->where('company_id', $companyId))->ignore($employeId)],
            'subcontratacion' => 'nullable|string|max:255',
        ];
    }
}
