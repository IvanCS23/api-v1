<?php

namespace App\Support\Sat;

use Carbon\CarbonImmutable;

/** Fase 6.27b — Datos públicos de un certificado del SAT leído en memoria. */
final class CsdCertificate
{
    public function __construct(
        public readonly string $rfc,
        public readonly string $serialNumber,
        public readonly ?string $name,
        public readonly ?string $branch,
        public readonly CarbonImmutable $validFrom,
        public readonly CarbonImmutable $validTo,
    ) {}

    /** La FIEL no trae sucursal (OU); el CSD sí. */
    public function isCsd(): bool
    {
        return $this->branch !== null;
    }

    public function isExpiredAt(CarbonImmutable $now): bool
    {
        return $this->validTo->lessThanOrEqualTo($now);
    }
}
