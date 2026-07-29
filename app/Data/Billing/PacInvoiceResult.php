<?php

namespace App\Data\Billing;

use Carbon\CarbonImmutable;

/**
 * Resultado normalizado de cualquier operación contra un PAC —
 * independiente de la forma de la respuesta cruda del proveedor
 * concreto (Facturapi u otro). `rawResponse` conserva la respuesta
 * original para auditoría/depuración, pero el resto de los campos son
 * el contrato estable que consume el dominio.
 */
final class PacInvoiceResult
{
    /**
     * @param  array<string, mixed>  $rawResponse
     */
    public function __construct(
        public readonly string $externalId,
        public readonly string $status,
        public readonly ?string $uuid = null,
        public readonly ?CarbonImmutable $stampedAt = null,
        public readonly ?string $cancellationStatus = null,
        public readonly array $rawResponse = [],
    ) {}
}
