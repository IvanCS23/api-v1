<?php

namespace App\Support\Sat;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Fase 6.27b — Lee en memoria un certificado del SAT (.cer, DER o PEM)
 * antes de enviarlo a Facturapi. Nunca lo guarda.
 *
 * - RFC: atributo x500UniqueIdentifier (OID 2.5.4.45) del sujeto; en una
 *   persona moral trae "RFC / RFC del representante", se toma el primero.
 * - Serie: el SAT codifica los dígitos del número de certificado en
 *   hexadecimal ("3330…" = "30…"); si no son dígitos se usa el decimal.
 * - CSD o FIEL: el CSD trae la sucursal en OU; la FIEL no.
 */
final class CsdCertificateReader
{
    public function read(string $contents): CsdCertificate
    {
        // Con un archivo que no es certificado OpenSSL emite un warning; aquí
        // basta con el false, y se vacía su cola de errores.
        $parsed = @openssl_x509_parse($this->toPem($contents));

        while (openssl_error_string() !== false) {
            // vaciar
        }

        if (! is_array($parsed)) {
            throw new InvalidArgumentException('El archivo .cer no es un certificado válido.');
        }

        $subject = is_array($parsed['subject'] ?? null) ? $parsed['subject'] : [];
        $uniqueId = $this->first($subject['x500UniqueIdentifier'] ?? null);
        $rfc = $uniqueId === null ? null : mb_strtoupper(trim(explode('/', $uniqueId)[0]));

        if ($rfc === null || $rfc === '') {
            throw new InvalidArgumentException('El certificado no contiene un RFC.');
        }

        return new CsdCertificate(
            rfc: $rfc,
            serialNumber: $this->serial($parsed),
            name: $this->first($subject['CN'] ?? null),
            branch: $this->first($subject['OU'] ?? null),
            validFrom: CarbonImmutable::createFromTimestampUTC((int) ($parsed['validFrom_time_t'] ?? 0)),
            validTo: CarbonImmutable::createFromTimestampUTC((int) ($parsed['validTo_time_t'] ?? 0)),
        );
    }

    private function toPem(string $contents): string
    {
        if (str_contains($contents, '-----BEGIN CERTIFICATE-----')) {
            return $contents;
        }

        return "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($contents), 64, "\n")."-----END CERTIFICATE-----\n";
    }

    /** @param array<string, mixed> $parsed */
    private function serial(array $parsed): string
    {
        $hex = (string) ($parsed['serialNumberHex'] ?? '');
        $decoded = $hex !== '' && strlen($hex) % 2 === 0 ? (string) hex2bin($hex) : '';

        return preg_match('/^\d+$/', $decoded) === 1 ? $decoded : (string) ($parsed['serialNumber'] ?? $hex);
    }

    private function first(mixed $value): ?string
    {
        $value = is_array($value) ? ($value[0] ?? null) : $value;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
