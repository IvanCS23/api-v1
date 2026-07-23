<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sin `toArray()` propio a propósito (ver ClientResource): JsonResource
 * serializa el modelo tal cual. Si el controller hace `$sale->load('items')`
 * antes de devolver el Resource, `items` aparece anidado automáticamente
 * — Eloquent incluye relaciones cargadas en `toArray()` por defecto.
 */
class SaleResource extends JsonResource
{
    //
}
