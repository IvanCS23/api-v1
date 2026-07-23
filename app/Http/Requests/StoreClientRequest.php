<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesEmptyStrings;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClientRequest extends FormRequest
{
    use NormalizesEmptyStrings;

    /**
     * Auditoría (revisión post-Etapa D): `ClientPolicy::create()` hoy
     * retorna `true` incondicionalmente — no existe ninguna granularidad
     * real por rol que mover aquí. Mantener `authorize(): true` en el
     * Form Request y dejar `$this->authorize('create', ...)` en el
     * controller (no duplicado, es la única llamada) es correcto
     * mientras eso no cambie. Riesgo documentado: cualquier usuario
     * autenticado de la empresa, sin importar su UserRole (admin,
     * accountant, sales, employee), puede crear clientes — ver
     * tests\Feature\Catalogs\AuthorizationGranularityTest.php.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyEmptyStrings(['no_exterior', 'no_interior', 'colonia', 'localidad', 'municipio']);

        if (! $this->filled('calle')) {
            $this->merge(['calle' => '']);
        }

        if (! $this->filled('estado')) {
            $this->merge(['estado' => '']);
        }

        if (! $this->filled('pais')) {
            $this->merge(['pais' => 'México']);
        }
    }

    /**
     * La empresa se obtiene exclusivamente de CurrentTenant (poblado por
     * TenantMiddleware desde el usuario autenticado). company_id nunca se
     * lee del payload: ni siquiera está en esta lista de reglas, así que
     * un valor enviado por el cliente se descarta antes de llegar a
     * $request->validated().
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(CurrentTenant::class)->id();

        return [
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('clients', 'email')->where(fn ($query) => $query->where('company_id', $companyId))],
            'rfc' => ['required', 'string', 'max:13', Rule::unique('clients', 'rfc')->where(fn ($query) => $query->where('company_id', $companyId))],
            'codigo_postal' => 'required|string|max:10',
            'regimen_fiscal' => 'required|string|max:10',
            'uso_cfdi' => 'required|string|max:10',
            'calle' => 'nullable|string|max:255',
            'no_exterior' => 'nullable|string|max:20',
            'no_interior' => 'nullable|string|max:20',
            'colonia' => 'nullable|string|max:255',
            'localidad' => 'nullable|string|max:255',
            'municipio' => 'nullable|string|max:255',
            'estado' => 'nullable|string|max:255',
            'pais' => 'nullable|string|max:255',
        ];
    }
}
