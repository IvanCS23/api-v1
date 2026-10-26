<?php

namespace App\Http\Requests;

use App\Enums\ProductType;
use App\Http\Requests\Concerns\NormalizesEmptyStrings;
use App\Http\Requests\Concerns\ValidatesProductFiscalData;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreProductRequest extends FormRequest
{
    use NormalizesEmptyStrings;
    use ValidatesProductFiscalData;

    /**
     * Auditoría (revisión post-Etapa D): `ProductPolicy::create()` retorna
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
        $this->nullifyEmptyStrings([
            'no_identificacion', 'cuenta_predial', 'clave_unidad', 'objeto_imp',
            'no_pedimento', 'impuesto_local', 'iva_retenido', 'ieps', 'isr',
        ]);
        $this->normalizeFiscalData();
    }

    /**
     * company_id nunca se lee del payload: se obtiene de CurrentTenant
     * solo para escopar la validación `unique`.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(CurrentTenant::class)->id();

        return [
            'name' => 'required|string|max:255',
            'no_identificacion' => ['nullable', 'string', 'max:255', Rule::unique('products', 'no_identificacion')->where(fn ($query) => $query->where('company_id', $companyId))],
            'descripcion' => 'required|string|max:255',
            'precio_unitario' => 'required|numeric|min:0',
            'cuenta_predial' => 'nullable|string|max:255',
            // Fase 6.26: clave_producto, clave_unidad, objeto_imp y tax_rate_id
            // (ver ValidatesProductFiscalData). La clave SAT ya no es única por
            // empresa: es una clasificación, no un identificador.
            ...$this->fiscalRules(creating: true),
            'no_pedimento' => 'nullable|string|max:255',
            'impuesto_local' => 'nullable|string|max:255',
            'iva' => 'nullable|numeric|min:0',
            'iva_retenido' => 'nullable|numeric|min:0',
            'ieps' => 'nullable|numeric|min:0',
            'isr' => 'nullable|numeric|min:0',
            'type' => ['sometimes', new Enum(ProductType::class)],
        ];
    }

    /**
     * Preserva el comportamiento actual del controller: los campos
     * opcionales enviados vacíos (normalizados a NULL arriba) se
     * eliminan del array antes de crear, en vez de guardarse como NULL
     * explícito (mismo resultado final para columnas nullable, pero es
     * el comportamiento que ya existía).
     *
     * IMPORTANTE — auditoría: el callback compara con `!== null`
     * explícitamente (nunca `array_filter($values)` sin callback, que
     * usa una comparación "truthy" y eliminaría también `0`, `0.0`,
     * `"0"`, `false` y arrays vacíos). Con este callback, `0`/`"0"` en
     * `precio_unitario`/`iva`/`iva_retenido`/`ieps`/`isr` se conservan
     * — ver tests\Feature\Catalogs\StoreProductRequestFalsyValuesTest.php.
     *
     * `validated('campo')` (con $key) no pasa por este filtro — delega
     * directo a `parent::validated()`, que no necesita el ajuste porque
     * ya se pide explícitamente esa clave.
     *
     * CAVEAT conocido: `$request->safe()` (el objeto ValidatedInput) NO
     * pasa por este override — `FormRequest::safe()` llama directo a
     * `$this->validator->safe()`, sin invocar `validated()`. Hoy no es un
     * bug real porque `ProductController::store()` usa `validated()`, no
     * `safe()`; si en el futuro se cambia a `safe()->all()`/`->toArray()`,
     * el filtro de nulls dejaría de aplicarse — vale la pena recordarlo
     * antes de hacer ese cambio.
     */
    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);

        if ($key !== null) {
            return $validated;
        }

        return $this->withDerivedIva(array_filter($validated, fn ($value) => $value !== null))
            + ['iva' => 0]; // sin tasa (objeto 01, 03, 04): sin IVA, nunca el default 16 de la columna.
    }
}
