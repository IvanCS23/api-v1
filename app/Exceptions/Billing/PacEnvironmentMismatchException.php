<?php

namespace App\Exceptions\Billing;

/**
 * Fase 6.31: la factura quedó fijada en otro ambiente del PAC (Test o
 * Live) que el de su empresa, o tiene una reserva previa sin ambiente
 * (anterior a la 6.31 sin backfill). La llave de la empresa no es la que
 * la creó.
 *
 * Se lanza antes de cualquier HTTP, igual que una configuración inválida,
 * así que hereda su clasificación de fallo definitivo.
 */
final class PacEnvironmentMismatchException extends PacConfigurationException
{
    public const REASON = 'pac_environment_mismatch';

    public function __construct(
        public readonly int $invoiceId,
        public readonly ?string $invoiceEnvironment,
        public readonly ?string $companyEnvironment,
    ) {
        parent::__construct(self::REASON, $invoiceEnvironment === null
            ? "La factura #{$invoiceId} tiene operaciones del PAC sin ambiente registrado (billing:backfill-pac-environment). No se contactó al PAC."
            : "La factura #{$invoiceId} se operó en el ambiente {$invoiceEnvironment} y su empresa opera en {$companyEnvironment}. No se contactó al PAC.");
    }
}
