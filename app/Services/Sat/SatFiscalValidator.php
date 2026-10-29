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

    /**
     * Régimen societario al final de la razón social: S.A., S.A. de C.V.,
     * S.A.P.I. de C.V., S. de R.L. (de C.V.), S.C., A.C., S.A.S.
     */
    private const CORPORATE_REGIME_SUFFIX = '/(?:^|[\s,])(?:S\.?\s*A\.?\s*P\.?\s*I\.?|S\.?\s*A\.?\s*S\.?|S\.?\s*A\.?|S\.?\s*DE\s+R\.?\s*L\.?|S\.?\s*C\.?|A\.?\s*C\.?)(?:\s*DE\s+C\.?\s*V\.?)?\s*$/iu';

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
        // Fase 6.26.1: con un RFC genérico lo que importa es 616/S01; se revisa
        // antes que el tipo de persona, que con XAXX/XEXX confundía ("no aplica
        // a una persona física").
        $generic = $this->isGenericRfc($rfc);

        if ($regime !== '') {
            if (! isset($regimes[$regime])) {
                $issues[] = $this->issue('CLIENT_FISCAL_REGIME_INVALID', 'regimen_fiscal', "El régimen fiscal {$regime} no existe en el catálogo del SAT.");
            } elseif ($generic && $regime !== self::GENERIC_REGIME) {
                $issues[] = $this->issue('CLIENT_REGIME_USE_INCOMPATIBLE', 'regimen_fiscal', 'Con un RFC genérico el régimen fiscal debe ser 616.');
            } elseif ($person !== null && ! $regimes[$regime][$person === self::PERSON_MORAL ? 'moral' : 'physical']) {
                $issues[] = $this->issue('CLIENT_FISCAL_REGIME_INVALID', 'regimen_fiscal', "El régimen fiscal {$regime} no aplica a una persona ".$this->personLabel($person).'.');
            } else {
                $regimeOk = true;
            }
        }

        if ($use !== '') {
            if (! isset($uses[$use])) {
                $issues[] = $this->issue('CLIENT_CFDI_USE_INVALID', 'uso_cfdi', "El uso de CFDI {$use} no existe en el catálogo del SAT.");
            } elseif ($generic && $use !== self::GENERIC_USE) {
                $issues[] = $this->issue('CLIENT_REGIME_USE_INCOMPATIBLE', 'uso_cfdi', 'Con un RFC genérico el uso de CFDI debe ser S01 (Sin efectos fiscales).');
            } elseif ($person !== null && ! $uses[$use][$person === self::PERSON_MORAL ? 'moral' : 'physical']) {
                $issues[] = $this->issue('CLIENT_CFDI_USE_INVALID', 'uso_cfdi', "El uso de CFDI {$use} no aplica a una persona ".$this->personLabel($person).'.');
            } else {
                $useOk = true;
            }
        }

        if ($regimeOk && $useOk && ! in_array($regime, $uses[$use]['regimes'], true)) {
            $issues[] = $this->issue('CLIENT_REGIME_USE_INCOMPATIBLE', 'uso_cfdi', "El uso de CFDI {$use} no es compatible con el régimen fiscal {$regime}.");
        }

        return $issues;
    }

    /**
     * Fase 6.27b — Datos fiscales del emisor (la empresa). A diferencia del
     * cliente, todos son obligatorios, el RFC no puede ser genérico y la
     * razón social va sin régimen societario (CFDI 4.0). La existencia del
     * C.P. la valida Facturapi: no hay catálogo local de c_CodigoPostal.
     *
     * @param  array{rfc?: ?string, legal_name?: ?string, fiscal_regime?: ?string, postal_code?: ?string}  $issuer
     * @return list<array{code: string, field: string, message: string}>
     */
    public function issuerIssues(array $issuer): array
    {
        $rfc = self::normalizeRfc($issuer['rfc'] ?? null) ?? '';
        $legalName = trim((string) ($issuer['legal_name'] ?? ''));
        $regime = trim((string) ($issuer['fiscal_regime'] ?? ''));
        $postalCode = trim((string) ($issuer['postal_code'] ?? ''));
        $issues = [];
        $person = null;

        if ($rfc === '') {
            $issues[] = $this->issue('COMPANY_RFC_MISSING', 'rfc', 'Falta el RFC de la empresa.');
        } elseif ($this->isGenericRfc($rfc)) {
            $issues[] = $this->issue('COMPANY_RFC_GENERIC', 'rfc', 'Un RFC genérico (XAXX010101000 o XEXX010101000) no puede emitir facturas.');
        } elseif (($person = $this->personType($rfc)) === null) {
            $issues[] = $this->issue('COMPANY_RFC_INVALID', 'rfc', 'El RFC no tiene un formato válido: 12 caracteres para persona moral o 13 para persona física (letras, fecha AAMMDD y homoclave).');
        }

        if ($legalName === '') {
            $issues[] = $this->issue('COMPANY_LEGAL_NAME_MISSING', 'legal_name', 'Falta la razón social de la empresa.');
        } elseif (preg_match(self::CORPORATE_REGIME_SUFFIX, $legalName) === 1) {
            $issues[] = $this->issue('COMPANY_LEGAL_NAME_INVALID', 'legal_name', 'Escribe la razón social sin el régimen societario (por ejemplo, sin "S.A. de C.V."), como aparece en la constancia de situación fiscal.');
        }

        $regimes = SatCatalog::taxRegimes();

        if ($regime === '') {
            $issues[] = $this->issue('COMPANY_FISCAL_REGIME_MISSING', 'fiscal_regime', 'Falta el régimen fiscal de la empresa.');
        } elseif (! isset($regimes[$regime])) {
            $issues[] = $this->issue('COMPANY_FISCAL_REGIME_INVALID', 'fiscal_regime', "El régimen fiscal {$regime} no existe en el catálogo del SAT.");
        } elseif ($person !== null && ! $regimes[$regime][$person === self::PERSON_MORAL ? 'moral' : 'physical']) {
            $issues[] = $this->issue('COMPANY_FISCAL_REGIME_INVALID', 'fiscal_regime', "El régimen fiscal {$regime} no aplica a una persona ".$this->personLabel($person).'.');
        }

        if ($postalCode === '') {
            $issues[] = $this->issue('COMPANY_POSTAL_CODE_MISSING', 'postal_code', 'Falta el código postal del domicilio fiscal (lugar de expedición).');
        } elseif (! preg_match('/^\d{5}$/', $postalCode)) {
            $issues[] = $this->issue('COMPANY_POSTAL_CODE_INVALID', 'postal_code', 'El código postal debe tener 5 dígitos.');
        }

        return $issues;
    }

    /**
     * Fase 6.27b — Con un RFC genérico (público en general o extranjero) el
     * SAT exige que el C.P. del receptor sea el lugar de expedición, es
     * decir, el C.P. de la empresa. Sin C.P. de la empresa no se compara:
     * eso ya lo reporta issuerIssues.
     */
    public function genericClientPostalCodeMismatch(?string $clientRfc, ?string $clientPostalCode, ?string $issuerPostalCode): bool
    {
        $issuerPostalCode = trim((string) $issuerPostalCode);

        return $this->isGenericRfc($clientRfc)
            && $issuerPostalCode !== ''
            && trim((string) $clientPostalCode) !== $issuerPostalCode;
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
