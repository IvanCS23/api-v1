<?php

namespace App\Support\Billing;

use App\Models\Invoice;

/**
 * Fase 6.27: organización de Facturapi registrada en las respuestas ya
 * guardadas de una factura (`pac_response` y `pac_draft_response`, ambas
 * cifradas). Sólo lee estado local; nunca hace HTTP.
 */
final class StoredPacOrganization
{
    /** La organización guardada, o null si no hay o las respuestas no coinciden. */
    public static function of(Invoice $invoice): ?string
    {
        $organizations = self::all($invoice);

        return count($organizations) === 1 ? $organizations[0] : null;
    }

    /** true si la emisión y el borrador dicen organizaciones distintas. */
    public static function conflicts(Invoice $invoice): bool
    {
        return count(self::all($invoice)) > 1;
    }

    /** @return list<string> */
    private static function all(Invoice $invoice): array
    {
        $found = [];

        foreach ([$invoice->pac_response, $invoice->pac_draft_response] as $response) {
            $organization = is_array($response) ? ($response['organization'] ?? null) : null;

            if (is_string($organization) && $organization !== '') {
                $found[] = $organization;
            }
        }

        return array_values(array_unique($found));
    }
}
