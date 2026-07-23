<?php

namespace App\Http\Requests\Concerns;

/**
 * Convierte campos opcionales enviados como cadena vacía a NULL antes de
 * validar, para que la regla `nullable` los trate como ausentes en lugar
 * de intentar aplicarles el resto de reglas (string/max/etc.) sobre "".
 *
 * Replica exactamente el comportamiento de los antiguos `normalizeInput()`
 * privados de ClientController/ProductController/EmployeController.
 */
trait NormalizesEmptyStrings
{
    protected function nullifyEmptyStrings(array $fields): void
    {
        $data = [];

        foreach ($fields as $field) {
            if ($this->has($field) && $this->input($field) === '') {
                $data[$field] = null;
            }
        }

        if ($data !== []) {
            $this->merge($data);
        }
    }
}
