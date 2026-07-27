<?php

namespace App\Policies;

use App\Models\InvoiceItem;
use App\Models\User;

/**
 * Autorización por empresa para InvoiceItem. Solo viewAny/view: las
 * líneas de una Invoice nunca se crean/editan/eliminan manualmente
 * (siempre las genera SaleToInvoiceConverter), así que no existen
 * métodos create/update/delete aquí.
 */
class InvoiceItemPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, InvoiceItem $invoiceItem): bool
    {
        return $user->company_id === $invoiceItem->company_id;
    }
}
