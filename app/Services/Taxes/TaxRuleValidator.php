<?php

namespace App\Services\Taxes;

use App\Enums\TaxFactorType;
use App\Enums\TaxType;
use App\Services\Sat\SatFiscalValidator;
use App\Support\Sat\SatCatalog;
use App\Support\Taxes\LegalTaxRules;

/**
 * Fase 6.34 (4): reglas de los impuestos de un concepto que el SAT rechaza
 * al timbrar (D2: sólo éstas son errores) más la lista cerrada de tasas de
 * retención (D3). Las reglas legales son avisos: ver LegalTaxRules.
 *
 * Fuentes: c_Impuesto, c_TipoFactor y c_TasaOCuota (SatCatalog); guía de
 * llenado (DPA, 2026), págs. 20–22 y 24: TasaOCuota fija del catálogo o
 * dentro del rango; Exento sin TasaOCuota ni Importe; un solo registro por
 * impuesto, factor y tasa; Tasa y Cuota en apartados distintos. Objeto 07
 * (IEPS sin IVA): contrato de Facturapi.
 *
 * Fuera del alcance de la 6.34: el 8 % de IVA fronterizo y el IEPS incluido
 * en el precio (sólo sum_before_taxes, D1).
 */
class TaxRuleValidator
{
    /**
     * @param  list<TaxSpec>  $specs
     * @return list<array{code: string, field: string, message: string}>
     */
    public function issues(array $specs, ?string $taxObject = null): array
    {
        $issues = [];
        $taxes = SatCatalog::taxes();
        $seen = [];

        foreach ($specs as $index => $spec) {
            $field = "taxes.{$index}";
            $name = $taxes[$spec->tax]['description'] ?? $spec->tax;

            if (! isset($taxes[$spec->tax])) {
                $issues[] = $this->issue('TAX_UNKNOWN', "{$field}.tax", "El impuesto {$spec->tax} no existe en c_Impuesto.");

                continue;
            }

            $kindAllowed = $spec->isWithholding() ? $taxes[$spec->tax]['withholding'] : $taxes[$spec->tax]['transfer'];
            if (! $kindAllowed) {
                $issues[] = $this->issue('TAX_KIND_NOT_ALLOWED', "{$field}.kind", "{$name} no admite ".($spec->isWithholding() ? 'retención' : 'traslado').' (c_Impuesto).');

                continue;
            }

            // Un solo registro por impuesto y tipo; sólo el IEPS trasladado
            // admite Tasa y Cuota a la vez (en apartados distintos).
            $key = $spec->tax.'|'.$spec->kind->value.($spec->isTransferredIeps() ? '|'.$spec->factor->value : '');
            if (isset($seen[$key])) {
                $issues[] = $this->issue('TAX_DUPLICATED', $field, "{$name} ".($spec->isWithholding() ? 'retenido' : 'trasladado').' aparece más de una vez en el concepto.');

                continue;
            }
            $seen[$key] = true;

            if ($spec->factor === TaxFactorType::Exento) {
                if ($spec->isWithholding()) {
                    $issues[] = $this->issue('TAX_EXEMPT_WITHHOLDING', "{$field}.factor", 'Una retención no puede ser exenta.');
                } elseif ($spec->rate !== null) {
                    $issues[] = $this->issue('TAX_EXEMPT_WITH_RATE', "{$field}.rate", 'Un impuesto exento no lleva tasa ni cuota.');
                }

                continue;
            }

            if ($spec->rate === null) {
                $issues[] = $this->issue('TAX_RATE_REQUIRED', "{$field}.rate", "Falta la tasa o cuota de {$name}.");

                continue;
            }

            if ($spec->isTransferredIeps() && $spec->iepsMode !== TaxSpec::IEPS_SUM_BEFORE_TAXES) {
                $issues[] = $this->issue('TAX_IEPS_MODE_UNSUPPORTED', "{$field}.ieps_mode", 'Por ahora el IEPS sólo se desglosa y se suma a la base de los demás impuestos (sum_before_taxes).');
            }

            $match = $this->catalogMatch($spec);
            if ($match === 'border_credit') {
                $issues[] = $this->issue('TAX_RATE_OUT_OF_SCOPE', "{$field}.rate", 'El IVA de 8 % de la región fronteriza todavía no está disponible.');
            } elseif ($match === null) {
                $issues[] = $this->issue('TAX_RATE_NOT_IN_CATALOG', "{$field}.rate", 'La '.($spec->factor === TaxFactorType::Cuota ? 'cuota' : 'tasa')." {$spec->rate} de {$name} ".($spec->isWithholding() ? 'retenido' : 'trasladado').' no está en c_TasaOCuota.');
            } elseif ($spec->isWithholding() && in_array($spec->tax, ['001', '002'], true) && ! in_array($spec->rate, LegalTaxRules::allowedWithholdingRates($spec->tax), true)) {
                $issues[] = $this->issue('TAX_WITHHOLDING_RATE_NOT_SUPPORTED', "{$field}.rate", "La tasa {$spec->rate} de {$name} retenido no está en la lista de tasas admitidas.");
            }
        }

        if ($taxObject !== null) {
            array_push($issues, ...$this->taxObjectIssues($specs, $taxObject));
        }

        return $issues;
    }

    /** 'match', 'border_credit' o null. */
    private function catalogMatch(TaxSpec $spec): ?string
    {
        $border = false;

        foreach (SatCatalog::taxRates() as $row) {
            if ($row['tax'] !== $spec->tax || $row['factor'] !== ucfirst($spec->factor->value)) {
                continue;
            }
            if (! ($spec->isWithholding() ? $row['withholding'] : $row['transfer'])) {
                continue;
            }
            if (bccomp($spec->rate, $row['min'], 6) < 0 || bccomp($spec->rate, $row['max'], 6) > 0) {
                continue;
            }
            if (! $row['border_credit']) {
                return 'match';
            }
            $border = true;
        }

        return $border ? 'border_credit' : null;
    }

    /**
     * @param  list<TaxSpec>  $specs
     * @return list<array{code: string, field: string, message: string}>
     */
    private function taxObjectIssues(array $specs, string $taxObject): array
    {
        $transferredIva = array_filter($specs, fn (TaxSpec $s): bool => $s->tax === '002' && $s->kind === TaxType::Traslado);
        $transferredIeps = array_filter($specs, fn (TaxSpec $s): bool => $s->isTransferredIeps());

        return match (true) {
            $taxObject === SatFiscalValidator::TAX_OBJECT_TAXED && $specs === [] => [$this->issue('TAX_OBJECT_REQUIRES_TAX', 'taxes', 'Con objeto de impuesto 02 el concepto debe llevar al menos un impuesto.')],
            in_array($taxObject, SatFiscalValidator::TAX_OBJECTS_WITHOUT_TAX, true) && $specs !== [] => [$this->issue('TAX_OBJECT_WITHOUT_TAX', 'taxes', "Con objeto de impuesto {$taxObject} el concepto no lleva impuestos.")],
            $taxObject === '07' && ($transferredIeps === [] || $transferredIva !== []) => [$this->issue('TAX_OBJECT_07_REQUIRES_IEPS', 'taxes', 'Con objeto de impuesto 07 el concepto lleva IEPS trasladado y no lleva IVA trasladado.')],
            default => [],
        };
    }

    /** @return array{code: string, field: string, message: string} */
    private function issue(string $code, string $field, string $message): array
    {
        return ['code' => $code, 'field' => $field, 'message' => $message];
    }
}
