<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesEmptyStrings;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeRequest extends FormRequest
{
    use NormalizesEmptyStrings;

    /**
     * Auditoría (revisión post-Etapa D): `EmployePolicy::create()` retorna
     * `true` incondicionalmente, sin granularidad por rol. Ver
     * StoreClientRequest para el razonamiento completo (aplica igual
     * aquí). Riesgo documentado y probado en
     * tests\Feature\Catalogs\AuthorizationGranularityTest.php.
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

        return [
            'email' => ['required', 'email', Rule::unique('employes', 'email')->where(fn ($query) => $query->where('company_id', $companyId))],
            'nombre' => 'required|string|max:255',
            'apellido_paterno' => 'required|string|max:255',
            'apellido_materno' => 'required|string|max:255',
            'curp' => ['required', 'string', 'max:18', Rule::unique('employes', 'curp')->where(fn ($query) => $query->where('company_id', $companyId))],
            'rfc' => ['required', 'string', 'max:13', Rule::unique('employes', 'rfc')->where(fn ($query) => $query->where('company_id', $companyId))],
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
            'clave_bancaria' => ['nullable', 'string', 'max:18', Rule::unique('employes', 'clave_bancaria')->where(fn ($query) => $query->where('company_id', $companyId))],
            'periodidad_pago' => 'required|string|max:255',
            'departamento' => 'required|string|max:255',
            'puesto' => 'required|string|max:255',
            'no_empleado' => ['required', 'string', 'max:255', Rule::unique('employes', 'no_empleado')->where(fn ($query) => $query->where('company_id', $companyId))],
            'seguro_social' => ['required', 'string', 'max:11', Rule::unique('employes', 'seguro_social')->where(fn ($query) => $query->where('company_id', $companyId))],
            'subcontratacion' => 'nullable|string|max:255',
        ];
    }
}
