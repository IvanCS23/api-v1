<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sin `toArray()` propio a propósito (ver SaleResource/QuoteResource):
 * JsonResource serializa el modelo tal cual, incluyendo `items` si el
 * controller hizo `$invoice->load('items')` antes de devolver el Resource.
 */
class InvoiceResource extends JsonResource
{
    //
}
