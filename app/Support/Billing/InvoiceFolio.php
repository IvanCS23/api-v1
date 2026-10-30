<?php

namespace App\Support\Billing;

/**
 * Fase 6.28: serie y folio del CFDI derivados del folio interno de la
 * factura. `FAC-00000019` se timbra como Serie "FAC" y Folio 19, así el
 * XML, el PDF de Facturapi y el panel muestran el mismo número.
 *
 * Facturapi documenta `series` como texto (máx. 25) y `folio_number` como
 * entero, así que los ceros a la izquierda se pierden: la forma visible del
 * folio CFDI es "FAC-19" (ver label()). El folio interno no cambia.
 *
 * Un folio que no tenga este formato no se puede timbrar: si se omitieran
 * los campos, Facturapi asignaría su propio consecutivo y el CFDI dejaría
 * de corresponder a la factura (ver INVOICE_FOLIO_INVALID).
 */
final class InvoiceFolio
{
    public const SERIES = 'FAC';

    public const PREFIX = self::SERIES.'-';

    /** Límite de un entero de 32 bits: lo que cabe en cualquier cliente de Facturapi. */
    private const MAX_NUMBER = 2147483647;

    private function __construct(
        public readonly string $series,
        public readonly int $number,
    ) {}

    public static function parse(?string $folio): ?self
    {
        if ($folio === null || preg_match('/^'.self::SERIES.'-(\d{8,10})$/', $folio, $matches) !== 1) {
            return null;
        }

        $number = (int) $matches[1];

        if ($number < 1 || $number > self::MAX_NUMBER) {
            return null;
        }

        return new self(self::SERIES, $number);
    }

    /** Forma visible de una serie y folio del CFDI ("FAC-19"), o null sin folio. */
    public static function label(?string $series, int|string|null $number): ?string
    {
        if ($number === null || $number === '') {
            return null;
        }

        return filled($series) ? "{$series}-{$number}" : (string) $number;
    }

    public function cfdiLabel(): string
    {
        return (string) self::label($this->series, $this->number);
    }
}
