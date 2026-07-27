<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Únicamente `notes` es editable vía este endpoint genérico. Ninguna
     * regla se declara para `status`, `folio`, `company_id`,
     * `created_by`, `sale_id`, `client_id`, `issued_at`, `cancelled_at`
     * ni ninguna columna del snapshot fiscal — un payload que los envíe
     * los ignora en silencio (`validated()` nunca los incluye), mismo
     * contrato ya establecido para Sale/Quote desde Fase 4 §5. Las
     * transiciones de estado solo ocurren vía los endpoints de acción
     * (ready/issue/cancel, ver InvoiceWorkflow).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
