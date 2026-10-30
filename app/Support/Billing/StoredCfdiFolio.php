<?php

namespace App\Support\Billing;

use App\Models\Invoice;

/**
 * Fase 6.28: serie y folio con los que quedó timbrado el CFDI, leídos de
 * la respuesta ya guardada (`pac_response`, cifrada). Sólo lee estado
 * local; nunca hace HTTP. El borrador no cuenta: Facturapi asigna el
 * folio al timbrar.
 */
final class StoredCfdiFolio
{
    /** @return array{series: ?string, folio_number: int}|null */
    public static function of(Invoice $invoice): ?array
    {
        $response = $invoice->pac_response;

        if (! is_array($response)) {
            return null;
        }

        $number = $response['folio_number'] ?? null;

        if (is_string($number) && ctype_digit($number)) {
            $number = (int) $number;
        }

        if (! is_int($number) || $number < 1 || $number > 4294967295) {
            return null;
        }

        $series = $response['series'] ?? null;

        return [
            'series' => is_string($series) && $series !== '' ? mb_substr($series, 0, 25) : null,
            'folio_number' => $number,
        ];
    }

    /**
     * true si el CFDI ya se timbró con la serie del ERP pero un folio que
     * no es el de la factura. Una serie distinta (la "F" de Facturapi) es
     * de una factura anterior a la 6.28 y no se reporta.
     */
    public static function mismatchesErp(Invoice $invoice): bool
    {
        $expected = InvoiceFolio::parse($invoice->folio);

        return $expected !== null
            && $invoice->cfdi_series === $expected->series
            && $invoice->cfdi_folio_number !== null
            && (int) $invoice->cfdi_folio_number !== $expected->number;
    }
}
