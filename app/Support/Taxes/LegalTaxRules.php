<?php

namespace App\Support\Taxes;

use App\Enums\TaxType;
use App\Services\Sat\SatFiscalValidator;
use App\Services\Taxes\TaxSpec;

/**
 * Fase 6.34: ÚNICO lugar de las reglas legales de retención y de la lista
 * cerrada de tasas de retención (D2, D3). TODO ESTO ESTÁ PENDIENTE DE
 * CONTADOR (ver docs/CONTEXTO_PROYECTO_FACTURAPRO.md, sección 8).
 *
 * - Las reglas legales NO bloquean (D2): son avisos con
 *   `pending_accountant = true`. El SAT no las valida al timbrar (según lo
 *   revisado; por confirmar); son obligaciones de LIVA, LISR y la RMF.
 * - La lista cerrada de tasas (D3) sí es un error de captura: sólo se
 *   aceptan estas tasas para IVA e ISR retenidos, además de estar dentro
 *   del rango de c_TasaOCuota.
 */
final class LegalTaxRules
{
    /**
     * D3: tasas de IVA retenido admitidas (fracción, 6 decimales).
     * - 0.106667: dos terceras partes del 16 % (honorarios y arrendamiento
     *   de persona física a persona moral; LIVA 1-A y RLIVA 3).
     * - 0.040000: autotransporte terrestre de bienes (LIVA 1-A, fr. III).
     * - 0.060000: servicios con personal a disposición (LIVA 1-A, fr. IV).
     * - 0.160000: retención total (p. ej. personas morales que adquieren de
     *   personas físicas desperdicios o con extranjeros sin establecimiento).
     */
    public const IVA_WITHHOLDING_RATES = ['0.040000', '0.060000', '0.106667', '0.160000'];

    /**
     * D3: tasas de ISR retenido admitidas.
     * - 0.100000: honorarios y arrendamiento de persona física a persona
     *   moral (LISR 106 y 116).
     * - 0.012500: RESICO persona física a persona moral (LISR 113-J).
     */
    public const ISR_WITHHOLDING_RATES = ['0.012500', '0.100000'];

    /** Régimen Simplificado de Confianza (c_RegimenFiscal). */
    public const REGIME_RESICO = '626';

    public const RESICO_ISR_RATE = '0.012500';

    /**
     * Las reglas legales que deben confirmarse con el contador (se listan en
     * el documento maestro).
     *
     * @var array<string, string>
     */
    public const PENDING_ACCOUNTANT = [
        'LEGAL_WITHHOLDING_RECEIVER_NOT_MORAL' => 'Las retenciones de IVA e ISR sólo aplican cuando el receptor es persona moral (RFC de 12 caracteres, no genérico).',
        'LEGAL_ISR_WITHHOLDING_ISSUER_MORAL' => 'El ISR retenido sólo aplica cuando el emisor es persona física.',
        'LEGAL_RESICO_ISR_MISSING' => 'Emisor persona física en RESICO (626) con receptor persona moral: se espera ISR retenido de 1.25 %.',
        'LEGAL_RESICO_ISR_RATE' => 'Emisor en RESICO (626): el ISR retenido debe ser de 1.25 %.',
        'IVA_WITHHOLDING_RATES' => 'Tasas de IVA retenido admitidas: 4 %, 6 %, 10.6667 % (2/3 del 16 %, con 6 decimales: 0.106667) y 16 %.',
        'ISR_WITHHOLDING_RATES' => 'Tasas de ISR retenido admitidas: 10 % y 1.25 %.',
    ];

    /** @return list<string> */
    public static function allowedWithholdingRates(string $tax): array
    {
        return match ($tax) {
            '002' => self::IVA_WITHHOLDING_RATES,
            '001' => self::ISR_WITHHOLDING_RATES,
            default => [],
        };
    }

    /**
     * Avisos legales (no bloquean) para un documento.
     *
     * @param  list<TaxSpec>  $specs  todos los impuestos del documento
     * @return list<array{code: string, field: string, message: string, pending_accountant: true}>
     */
    public static function warnings(?string $issuerRfc, ?string $issuerRegime, ?string $receiverRfc, array $specs): array
    {
        $validator = new SatFiscalValidator;
        $issuer = $validator->personType($issuerRfc);
        $receiverIsMoral = ! $validator->isGenericRfc($receiverRfc) && $validator->personType($receiverRfc) === SatFiscalValidator::PERSON_MORAL;

        $withholdings = array_values(array_filter($specs, fn (TaxSpec $spec): bool => $spec->kind === TaxType::Retencion));
        $isr = array_values(array_filter($withholdings, fn (TaxSpec $spec): bool => $spec->tax === '001'));
        $warnings = [];

        if ($withholdings !== [] && ! $receiverIsMoral) {
            $warnings[] = self::warning('LEGAL_WITHHOLDING_RECEIVER_NOT_MORAL', 'taxes');
        }
        if ($isr !== [] && $issuer === SatFiscalValidator::PERSON_MORAL) {
            $warnings[] = self::warning('LEGAL_ISR_WITHHOLDING_ISSUER_MORAL', 'taxes');
        }
        if ($issuerRegime === self::REGIME_RESICO && $issuer === SatFiscalValidator::PERSON_PHYSICAL) {
            if ($isr === [] && $receiverIsMoral) {
                $warnings[] = self::warning('LEGAL_RESICO_ISR_MISSING', 'taxes');
            }
            foreach ($isr as $spec) {
                if ($spec->rate !== self::RESICO_ISR_RATE) {
                    $warnings[] = self::warning('LEGAL_RESICO_ISR_RATE', 'taxes');

                    break;
                }
            }
        }

        return $warnings;
    }

    /** @return array{code: string, field: string, message: string, pending_accountant: true} */
    private static function warning(string $code, string $field): array
    {
        return ['code' => $code, 'field' => $field, 'message' => self::PENDING_ACCOUNTANT[$code].' (Pendiente de contador.)', 'pending_accountant' => true];
    }
}
