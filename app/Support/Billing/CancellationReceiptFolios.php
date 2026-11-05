<?php

namespace App\Support\Billing;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Fase 6.31 — Folios de un acuse de cancelación del SAT.
 *
 * Estructura aceptada (Acuse/Folios con UUID y EstatusUUID hermanos, por
 * nombre local): la que muestran fuentes secundarias de otros PAC. Se
 * confirma con el primer acuse Live; no se acepta otra estructura sin
 * evidencia real.
 *
 * EstatusUUID 201 (solicitud recibida) y 202 (previamente cancelado) sólo
 * indican que el SAT procesó la solicitud; la prueba de la cancelación
 * sigue siendo pac_status=canceled y cancellation_status=accepted, que la
 * descarga exige antes de pedir el acuse. Cualquier otro valor, o su
 * ausencia, bloquea el acuse.
 */
final class CancellationReceiptFolios
{
    public const ACCEPTED_STATUSES = ['201', '202'];

    /** @return list<array{uuid: string, status: ?string}> */
    public static function of(DOMDocument $dom): array
    {
        $xpath = new DOMXPath($dom);
        $folios = $xpath->query('/*[local-name()="Acuse"]/*[local-name()="Folios"]');
        $result = [];

        if ($folios === false) {
            return [];
        }

        foreach ($folios as $folio) {
            if (! $folio instanceof DOMElement) {
                continue;
            }

            $uuids = $xpath->query('./*[local-name()="UUID"]', $folio);
            $statuses = $xpath->query('./*[local-name()="EstatusUUID"]', $folio);
            $status = $statuses !== false && $statuses->length === 1 ? trim((string) $statuses->item(0)?->textContent) : null;

            foreach ($uuids === false ? [] : $uuids as $uuid) {
                $result[] = [
                    'uuid' => trim((string) $uuid->textContent),
                    'status' => $status === '' ? null : $status,
                ];
            }
        }

        return $result;
    }

    public static function isAccepted(?string $status): bool
    {
        return $status !== null && in_array($status, self::ACCEPTED_STATUSES, true);
    }

    /** EstatusUUID seguro de mostrar: sólo un código numérico corto. */
    public static function displayStatus(?string $status): string
    {
        if ($status === null) {
            return '(ausente)';
        }

        return preg_match('/\A\d{1,4}\z/', $status) === 1 ? $status : '[no numérico]';
    }
}
