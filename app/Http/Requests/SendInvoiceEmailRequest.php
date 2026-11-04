<?php

namespace App\Http\Requests;

use App\Support\Billing\InvoiceEmailRecipients;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Fase 6.30: `{ confirm, recipients? }`. Sin `recipients` se usan los del
 * cliente. El formato y el máximo se revisan aquí (422 con el campo) y otra
 * vez en el servicio; si un rol puede editar la lista lo decide el
 * controlador.
 */
class SendInvoiceEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'confirm' => ['required', 'accepted'],
            'recipients' => ['sometimes', 'array', 'min:1', 'max:'.InvoiceEmailRecipients::MAX],
            'recipients.*' => ['required', 'string', 'max:255', 'email:rfc'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'recipients.max' => 'Puedes enviar la factura a un máximo de '.InvoiceEmailRecipients::MAX.' destinatarios.',
            'recipients.min' => 'Agrega al menos un destinatario.',
            'recipients.*.email' => 'Uno de los destinatarios no es un correo válido.',
        ];
    }

    /** @return list<string>|null */
    public function recipients(): ?array
    {
        return $this->has('recipients') ? array_values((array) $this->validated('recipients')) : null;
    }
}
