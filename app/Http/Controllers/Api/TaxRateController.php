<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaxType;
use App\Http\Controllers\Controller;
use App\Http\Resources\TaxRateResource;
use App\Models\TaxRate;

/**
 * Fase 6.25: catálogo de tasas para las líneas de venta. Sólo traslados
 * activos: el PAC todavía no admite retenciones
 * (INVOICE_ITEM_WITHHOLDING_UNSUPPORTED). El catálogo es global, no por
 * empresa.
 */
class TaxRateController extends Controller
{
    public function index()
    {
        return TaxRateResource::collection(
            TaxRate::query()
                ->where('active', true)
                ->where('tax_type', TaxType::Traslado->value)
                ->orderBy('id')
                ->get(),
        );
    }
}
