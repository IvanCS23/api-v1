<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Deliberadamente sin `toArray()` propio: JsonResource, sin overrides,
 * serializa el modelo tal cual (mismo shape plano que ya consume
 * app-front hoy vía `response()->json($client)`). No renombra ni oculta
 * columnas, no agrega relaciones. Es el punto de extensión para cuando
 * el frontend esté listo para un contrato distinto — hasta entonces, el
 * contrato actual (documentado en tests/Feature/Catalogs/*ApiContractTest.php)
 * se mantiene byte a byte.
 */
class ClientResource extends JsonResource
{
    //
}
