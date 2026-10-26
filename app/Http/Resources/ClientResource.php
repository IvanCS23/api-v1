<?php

namespace App\Http\Resources;

use App\Services\Sat\SatFiscalValidator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa el modelo tal cual (mismo shape plano que ya consume app-front;
 * contrato en tests/Feature/Catalogs/*ApiContractTest.php) y, desde la Fase
 * 6.26, agrega `fiscal_issues`: los problemas SAT del cliente guardado
 * ({code, field, message}, ver SatFiscalValidator), para que la pantalla
 * avise antes de que el readiness de una venta lo bloquee.
 */
class ClientResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'fiscal_issues' => app(SatFiscalValidator::class)->clientIssues(
                $this->resource->only(['rfc', 'codigo_postal', 'regimen_fiscal', 'uso_cfdi']),
            ),
        ];
    }
}
