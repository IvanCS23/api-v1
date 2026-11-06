<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Fase 6.31: desde la web sólo se edita el nombre. El correo es el
        // usuario de acceso y lo cambia un Admin por la API, como el resto
        // de los datos del usuario.
        return ['name' => $this->nameRules()];
    }
}
