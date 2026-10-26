<?php

namespace App\Http\Requests;

use App\Enums\ProductType;
use App\Http\Requests\Concerns\NormalizesEmptyStrings;
use App\Http\Requests\Concerns\ValidatesProductFiscalData;
use App\Models\Product;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateProductRequest extends FormRequest
{
    use NormalizesEmptyStrings;
    use ValidatesProductFiscalData;

    /**
     * Auditoría (revisión post-Etapa D): igual que UpdateClientRequest —
     * `ProductPolicy::update()` compara `company_id`, pero es
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
        $this->nullifyEmptyStrings([
            'no_identificacion', 'cuenta_predial', 'clave_unidad', 'objeto_imp',
            'no_pedimento', 'impuesto_local', 'iva_retenido', 'ieps', 'isr',
        ]);
        $this->normalizeFiscalData();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(CurrentTenant::class)->id();
        $productId = $this->route('id');

        return [
            'name' => 'sometimes|string|max:255',
            'descripcion' => 'sometimes|string|max:255',
            'precio_unitario' => 'sometimes|numeric|min:0',
            'cuenta_predial' => 'nullable|string|max:255',
            // Fase 6.26: ver ValidatesProductFiscalData.
            ...$this->fiscalRules(creating: false),
            'no_pedimento' => 'nullable|string|max:255',
            'impuesto_local' => 'nullable|string|max:255',
            'iva' => 'nullable|numeric|min:0',
            'iva_retenido' => 'nullable|numeric|min:0',
            'ieps' => 'nullable|numeric|min:0',
            'isr' => 'nullable|numeric|min:0',
            'no_identificacion' => ['nullable', 'string', 'max:255', Rule::unique('products', 'no_identificacion')->where(fn ($query) => $query->where('company_id', $companyId))->ignore($productId)],
            'type' => ['sometimes', new Enum(ProductType::class)],
        ];
    }

    /**
     * Fase 6.26: `iva` se deriva de `tax_rate_id` y nunca se guarda crudo
     * (un `iva` null llegaba a la columna NOT NULL y respondía 500).
     */
    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);

        return $key === null ? $this->withDerivedIva($validated) : $validated;
    }

    /** @return array<string, mixed> */
    protected function currentFiscalData(): array
    {
        $product = Product::find($this->route('id'));

        return $product === null ? [] : $product->only(['clave_producto', 'clave_unidad', 'objeto_imp', 'tax_rate_id']);
    }
}
