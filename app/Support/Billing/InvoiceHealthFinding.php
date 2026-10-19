<?php

namespace App\Support\Billing;

use App\Enums\InvoiceHealthAction;
use App\Enums\InvoiceHealthStatus;

/**
 * Hallazgo de salud sanitizado: sólo un código estable, su nivel, la
 * acción sugerida y la antigüedad. Nunca transporta payloads PAC,
 * errores crudos, rutas, llaves ni identificadores fiscales.
 *
 * Un hallazgo con nivel Valid es informativo: se reporta pero no eleva
 * el estado global de la factura ni dispara --fail-on.
 */
final class InvoiceHealthFinding
{
    public function __construct(
        public readonly string $code,
        public readonly InvoiceHealthStatus $status,
        public readonly InvoiceHealthAction $action,
        public readonly ?int $ageMinutes = null,
    ) {}

    public function isInformational(): bool
    {
        return $this->status === InvoiceHealthStatus::Valid;
    }

    /** @return array{code: string, status: string, informational: bool, action: string, age_minutes: ?int} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'status' => $this->status->value,
            'informational' => $this->isInformational(),
            'action' => $this->action->value,
            'age_minutes' => $this->ageMinutes,
        ];
    }
}
