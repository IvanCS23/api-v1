<?php

namespace App\Support\Billing;

/**
 * Fase 6.28: serie y folio del CFDI derivados del folio interno de la
 * factura. `FAC-00000019` se timbra como Serie "FAC" y Folio 19, así el
 * XML, el PDF de Facturapi y el panel muestran el mismo número.
 *
 * Facturapi documenta `series` como texto (máx. 25) y `folio_number` como
 * entero, así que los ceros a la izquierda se pierden. Fase 6.29: la forma
 * visible del folio CFDI es "FAC19", igual que la imprime el PDF de
 * Facturapi (ver label()). El folio interno no cambia.
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

    /**
     * Fase 6.28: interpreta lo que se escribe para buscar un folio CFDI:
     * "19", "FAC-19", "FAC 19", "FAC19" o "fac-0019". Devuelve la serie (en
     * mayúsculas, o null si sólo se escribió el número) y el número, o null
     * si el texto no tiene esa forma.
     *
     * @return array{series: ?string, number: int}|null
     */
    public static function parseSearch(string $search): ?array
    {
        if (preg_match('/^\s*([A-Za-z]{1,25})?[\s-]*(\d{1,10})\s*$/', $search, $matches) !== 1) {
            return null;
        }

        $number = (int) $matches[2];

        if ($number < 1 || $number > 4294967295) {
            return null;
        }

        return [
            'series' => $matches[1] !== '' ? strtoupper($matches[1]) : null,
            'number' => $number,
        ];
    }

    /** Folio interno del que sale un folio CFDI de la serie del ERP ("FAC-00000019"). */
    public static function erpFolio(int $number): string
    {
        return self::PREFIX.str_pad((string) $number, 8, '0', STR_PAD_LEFT);
    }

    /**
     * Forma visible de una serie y folio del CFDI, o null sin folio. Fase
     * 6.29: sin separador ("FAC19", "F10"), como el PDF de Facturapi. Es
     * inequívoca porque las series del ERP y de Facturapi son sólo letras.
     */
    public static function label(?string $series, int|string|null $number): ?string
    {
        if ($number === null || $number === '') {
            return null;
        }

        return filled($series) ? "{$series}{$number}" : (string) $number;
    }

    /**
     * Si el folio CFDI timbrado es el que sale del folio interno; null si
     * todavía no hay folio CFDI. false en las facturas anteriores a la 6.28
     * (Facturapi les asignó la serie "F").
     */
    public static function matchesErp(?string $erpFolio, ?string $series, int|string|null $number): ?bool
    {
        $label = self::label($series, $number);

        return $label === null ? null : self::parse($erpFolio)?->cfdiLabel() === $label;
    }

    public function cfdiLabel(): string
    {
        return (string) self::label($this->series, $this->number);
    }
}
