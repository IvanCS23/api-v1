<?php

namespace App\Services\Sat;

use App\Support\Sat\SatCatalog;

/**
 * Fase 6.26: reglas SAT de captura, compartidas por los Form Requests, el
 * readiness de ventas, el reporte sat:audit-catalog-data y los Resources.
 *
 * Cada problema es {code, field, message}; `field` es el atributo del modelo
 * (rfc, codigo_postal, …) y quien lo usa le antepone su prefijo. Un valor
 * vacío no se reporta aquí: la obligatoriedad la cubren las reglas
 * `required` y los códigos *_MISSING del readiness. Las compatibilidades
 * sólo se evalúan cuando cada parte es válida por sí misma, para no repetir
 * el mismo problema con dos códigos.
 *
 * Catálogos y matriz: App\Support\Sat\SatCatalog (archivo oficial del SAT).
 */
final class SatFiscalValidator
{
    public const RFC_GENERIC_NATIONAL = 'XAXX010101000';

    public const RFC_GENERIC_FOREIGN = 'XEXX010101000';

    /** Régimen y uso que el SAT exige con un RFC genérico. */
    public const GENERIC_REGIME = '616';

    public const GENERIC_USE = 'S01';

    public const PERSON_MORAL = 'moral';

    public const PERSON_PHYSICAL = 'physical';

    /**
     * Objeto de impuesto con traslado obligatorio (02) y los que no admiten
     * el nodo Impuestos en el concepto (01, 03, 04). 05–08 son casos
     * especiales del SAT que esta fase no valida contra el IVA.
     */
    public const TAX_OBJECT_TAXED = '02';

    public const TAX_OBJECTS_WITHOUT_TAX = ['01', '03', '04'];

    /** Moral: 3 letras; física: 4. Luego AAMMDD y homoclave de 3. */
    private const RFC_PATTERN = '/^([A-ZÑ&]{3,4})(\d{2})(\d{2})(\d{2})([A-Z\d]{3})$/u';

    public static function normalizeRfc(?string $rfc): ?string
    {
        return $rfc === null ? null : mb_strtoupper(trim($rfc));
    }

    public function isGenericRfc(?string $rfc): bool
    {
        return in_array(self::normalizeRfc($rfc), [self::RFC_GENERIC_NATIONAL, self::RFC_GENERIC_FOREIGN], true);
    }

    /** Persona moral (12 caracteres) o física (13), o null si el RFC no es válido. */
    public function personType(?string $rfc): ?string
    {
        $rfc = self::normalizeRfc($rfc);

        if ($rfc === null || ! preg_match(self::RFC_PATTERN, $rfc, $parts)) {
            return null;
        }

        // Fecha AAMMDD; el año 2000 es bisiesto, así que admite el 29 de febrero.
        if (! checkdate((int) $parts[3], (int) $parts[4], 2000)) {
            return null;
        }

        return mb_strlen($parts[1]) === 3 ? self::PERSON_MORAL : self::PERSON_PHYSICAL;
    }

    /**
     * @param  array{rfc?: ?string, codigo_postal?: ?string, regimen_fiscal?: ?string, uso_cfdi?: ?string}  $client
     * @return list<array{code: string, field: string, message: string}>
     */
    public function clientIssues(array $client): array
    {
        $rfc = self::normalizeRfc($client['rfc'] ?? null);
        $postalCode = trim((string) ($client['codigo_postal'] ?? ''));
        $regime = trim((string) ($client['regimen_fiscal'] ?? ''));
        $use = mb_strtoupper(trim((string) ($client['uso_cfdi'] ?? '')));
        $issues = [];

        $person = null;
        if ($rfc !== null && $rfc !== '') {
            $person = $this->personType($rfc);
            if ($person === null) {
                $issues[] = $this->issue('CLIENT_RFC_INVALID', 'rfc', 'El RFC no tiene un formato válido: 12 caracteres para persona moral o 13 para persona física (letras, fecha AAMMDD y homoclave).');
            }
        }

        if ($postalCode !== '' && ! preg_match('/^\d{5}$/', $postalCode)) {
            $issues[] = $this->issue('CLIENT_POSTAL_CODE_INVALID', 'codigo_postal', 'El código postal debe tener 5 dígitos.');
        }

        $regimes = SatCatalog::taxRegimes();
        $uses = SatCatalog::cfdiUses();
        $regimeOk = false;
        $useOk = false;

        if ($regime !== '') {
            if (! isset($regimes[$regime])) {
                $issues[] = $this->issue('CLIENT_FISCAL_REGIME_INVALID', 'regimen_fiscal', "El régimen fiscal {$regime} no existe en el catálogo del SAT.");
            } elseif ($person !== null && ! $regimes[$regime][$person === self::PERSON_MORAL ? 'moral' : 'physical']) {
                $issues[] = $this->issue('CLIENT_FISCAL_REGIME_INVALID', 'regimen_fiscal', "El régimen fiscal {$regime} no aplica a una persona ".$this->personLabel($person).'.');
            } else {
                $regimeOk = true;
            }
        }

        if ($use !== '') {
            if (! isset($uses[$use])) {
                $issues[] = $this->issue('CLIENT_CFDI_USE_INVALID', 'uso_cfdi', "El uso de CFDI {$use} no existe en el catálogo del SAT.");
            } elseif ($person !== null && ! $uses[$use][$person === self::PERSON_MORAL ? 'moral' : 'physical']) {
                $issues[] = $this->issue('CLIENT_CFDI_USE_INVALID', 'uso_cfdi', "El uso de CFDI {$use} no aplica a una persona ".$this->personLabel($person).'.');
            } else {
                $useOk = true;
            }
        }

        if ($this->isGenericRfc($rfc)) {
            if ($regimeOk && $regime !== self::GENERIC_REGIME) {
                $issues[] = $this->issue('CLIENT_REGIME_USE_INCOMPATIBLE', 'regimen_fiscal', 'Con un RFC genérico el régimen fiscal debe ser 616 (Sin obligaciones fiscales).');
                $regimeOk = false;
            }
            if ($useOk && $use !== self::GENERIC_USE) {
                $issues[] = $this->issue('CLIENT_REGIME_USE_INCOMPATIBLE', 'uso_cfdi', 'Con un RFC genérico el uso de CFDI debe ser S01 (Sin efectos fiscales).');
                $useOk = false;
            }
        }

        if ($regimeOk && $useOk && ! in_array($regime, $uses[$use]['regimes'], true)) {
            $issues[] = $this->issue('CLIENT_REGIME_USE_INCOMPATIBLE', 'uso_cfdi', "El uso de CFDI {$use} no es compatible con el régimen fiscal {$regime}.");
        }

        return $issues;
    }

    /**
     * Clave SAT, unidad, objeto de impuesto y su coherencia con el IVA del
     * producto (`tax_rate_id`). `$productKeyExists` / `$unitKeyExists`
     * permiten pasar el resultado de una consulta por lotes
     * (SatCatalog::existingProductKeys); si son null se consulta aquí.
     *
     * @param  array{clave_producto?: ?string, clave_unidad?: ?string, objeto_imp?: ?string, tax_rate_id?: int|string|null}  $product
     * @return list<array{code: string, field: string, message: string}>
     */
    public function productIssues(array $product, ?bool $productKeyExists = null, ?bool $unitKeyExists = null): array
    {
        $productKey = trim((string) ($product['clave_producto'] ?? ''));
        $unitKey = mb_strtoupper(trim((string) ($product['clave_unidad'] ?? '')));
        $taxObject = trim((string) ($product['objeto_imp'] ?? ''));
        $hasTax = filled($product['tax_rate_id'] ?? null);
        $issues = [];

        if ($productKey !== '') {
            if (! preg_match('/^\d{8}$/', $productKey)) {
                $issues[] = $this->issue('PRODUCT_SAT_KEY_INVALID', 'clave_producto', 'La clave de producto SAT debe tener 8 dígitos.');
            } elseif (! ($productKeyExists ?? SatCatalog::productKeyExists($productKey))) {
                $issues[] = $this->issue('PRODUCT_SAT_KEY_INVALID', 'clave_producto', "La clave de producto {$productKey} no existe en el catálogo c_ClaveProdServ del SAT.");
            }
        }

        if ($unitKey !== '' && ! ($unitKeyExists ?? SatCatalog::unitKeyExists($unitKey))) {
            $issues[] = $this->issue('PRODUCT_UNIT_KEY_INVALID', 'clave_unidad', "La clave de unidad {$unitKey} no existe en el catálogo c_ClaveUnidad del SAT.");
        }

        if ($taxObject !== '') {
            if (! SatCatalog::isTaxObject($taxObject)) {
                $issues[] = $this->issue('PRODUCT_TAX_OBJECT_INVALID', 'objeto_imp', "El objeto de impuesto {$taxObject} no existe en el catálogo c_ObjetoImp del SAT.");
            } elseif ($taxObject === self::TAX_OBJECT_TAXED && ! $hasTax) {
                $issues[] = $this->issue('PRODUCT_TAX_OBJECT_MISMATCH', 'tax_rate_id', 'Con objeto de impuesto 02 el producto debe tener IVA (16 %, 0 % o exento).');
            } elseif (in_array($taxObject, self::TAX_OBJECTS_WITHOUT_TAX, true) && $hasTax) {
                $issues[] = $this->issue('PRODUCT_TAX_OBJECT_MISMATCH', 'tax_rate_id', "Con objeto de impuesto {$taxObject} el producto no lleva IVA: quita la tasa o usa el objeto 02.");
            }
        }

        return $issues;
    }

    private function personLabel(string $person): string
    {
        return $person === self::PERSON_MORAL ? 'moral' : 'física';
    }

    /** @return array{code: string, field: string, message: string} */
    private function issue(string $code, string $field, string $message): array
    {
        return ['code' => $code, 'field' => $field, 'message' => $message];
    }
}
