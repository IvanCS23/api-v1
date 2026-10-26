<?php

namespace App\Http\Requests\Concerns;

use App\Services\Sat\SatFiscalValidator;
use Illuminate\Validation\Validator;

/**
 * Fase 6.26: RFC, código postal, régimen fiscal y uso de CFDI contra los
 * catálogos del SAT y su matriz de compatibilidad (SatFiscalValidator).
 *
 * Se evalúa la combinación final: lo que llega en el payload y, en una
 * edición, lo que el cliente ya tenía. Por eso un cliente guardado con datos
 * inválidos debe corregirse en cuanto se edita cualquiera de sus datos
 * fiscales; editar sólo datos no fiscales (nombre, correo, domicilio) no lo
 * exige.
 */
trait ValidatesClientFiscalData
{
    private const FISCAL_FIELDS = ['rfc', 'codigo_postal', 'regimen_fiscal', 'uso_cfdi'];

    protected function normalizeFiscalData(): void
    {
        $data = [];

        if (is_string($this->input('rfc'))) {
            $data['rfc'] = SatFiscalValidator::normalizeRfc($this->input('rfc'));
        }

        if (is_string($this->input('uso_cfdi'))) {
            $data['uso_cfdi'] = mb_strtoupper(trim($this->input('uso_cfdi')));
        }

        foreach (['codigo_postal', 'regimen_fiscal'] as $field) {
            if (is_string($this->input($field))) {
                $data[$field] = trim($this->input($field));
            }
        }

        $this->merge($data);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $sent = array_filter(self::FISCAL_FIELDS, fn (string $field): bool => $this->exists($field));

            if ($sent === [] || $validator->errors()->hasAny(self::FISCAL_FIELDS)) {
                return;
            }

            $client = $this->currentFiscalData();
            foreach ($sent as $field) {
                $client[$field] = $this->input($field);
            }

            foreach (app(SatFiscalValidator::class)->clientIssues($client) as $issue) {
                $validator->errors()->add($issue['field'], $issue['message']);
            }
        });
    }

    /**
     * Datos fiscales que el cliente ya tenía (vacíos al crear).
     *
     * @return array<string, ?string>
     */
    protected function currentFiscalData(): array
    {
        return [];
    }
}
