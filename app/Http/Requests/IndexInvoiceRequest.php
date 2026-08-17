<?php

namespace App\Http\Requests;

use App\Enums\InvoiceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::enum(InvoiceStatus::class)],
            'payment_method' => ['nullable', Rule::in(['PUE', 'PPD'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 20, 50, 100])],
            'sort' => ['nullable', Rule::in(['created_at', 'issued_at', 'folio', 'total'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }
}
