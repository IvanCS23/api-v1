<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesEmptyStrings;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClientRequest extends FormRequest
{
    use NormalizesEmptyStrings;

    /**
     * Auditoría (revisión post-Etapa D): `ClientPolicy::update()` sí
     * compara `company_id`, pero esa comparación es inalcanzable en la
     * práctica — el Global Scope ya impide que `Client::findOrFail()` en
     * el controller encuentre un registro de otra empresa (404 antes de
     * llegar a la policy). Mover la autorización aquí no cambiaría
     * ningún comportamiento observable y duplicaría la consulta (la
     * policy tendría que resolver el modelo por su cuenta). Se mantiene
     * `authorize(): true` + `$this->authorize('update', ...)` en el
     * controller, sin duplicar. Ver StoreClientRequest para el detalle
     * completo del razonamiento.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyEmptyStrings(['no_exterior', 'no_interior', 'colonia', 'localidad', 'municipio']);
    }

    /**
     * Igual que en StoreClientRequest: company_id nunca se lee del
     * payload. `ignore($clientId)` solo excluye el propio registro de la
     * comprobación de unicidad, ya restringida a la misma empresa por el
     * `where('company_id', ...)` — no permite consultar ni ignorar
     * registros de otra empresa.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(CurrentTenant::class)->id();
        $clientId = $this->route('id');

        return [
            'name' => 'sometimes|required|string|max:255',
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('clients', 'email')->where(fn ($query) => $query->where('company_id', $companyId))->ignore($clientId)],
            'rfc' => ['sometimes', 'required', 'string', 'max:13', Rule::unique('clients', 'rfc')->where(fn ($query) => $query->where('company_id', $companyId))->ignore($clientId)],
            'codigo_postal' => 'sometimes|required|string|max:10',
            'regimen_fiscal' => 'sometimes|required|string|max:10',
            'uso_cfdi' => 'sometimes|required|string|max:10',
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
