<?php

namespace App\Support\Billing;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use Illuminate\Support\Facades\Validator;

/**
 * Fase 6.30: destinatarios del envío de una factura por correo.
 *
 * Los por defecto salen de NUESTRA base (correo principal y los dos
 * opcionales del cliente), nunca de lo que Facturapi tenga guardado. Se
 * normalizan igual en todos lados: sin espacios, sin vacíos y sin repetidos
 * (sin distinguir mayúsculas), conservando el orden.
 */
final class InvoiceEmailRecipients
{
    public const MAX = 5;

    /** @return list<string> */
    public static function defaultsFor(Invoice $invoice): array
    {
        $client = Client::withoutGlobalScope(CompanyScope::class)
            ->withTrashed()
            ->where('company_id', $invoice->company_id)
            ->find($invoice->client_id);

        if ($client === null) {
            return [];
        }

        $candidates = self::normalize([$client->email, $client->email_optional_1, $client->email_optional_2]);

        // Un correo guardado antes de validar los opcionales se descarta en
        // silencio: el usuario ve la lista final antes de enviar.
        return array_values(array_filter($candidates, fn (string $email): bool => self::isValid($email)));
    }

    /**
     * @param  array<int, mixed>  $emails
     * @return list<string>
     */
    public static function normalize(array $emails): array
    {
        $seen = [];
        $result = [];

        foreach ($emails as $email) {
            $email = is_string($email) ? trim($email) : '';
            $key = mb_strtolower($email);

            if ($email === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $email;
        }

        return $result;
    }

    public static function isValid(string $email): bool
    {
        return mb_strlen($email) <= 255
            && Validator::make(['email' => $email], ['email' => 'email:rfc'])->passes();
    }

    /**
     * true si las dos listas tienen las mismas direcciones (sin importar orden
     * ni mayúsculas). Ventas sólo puede enviar a las por defecto.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    public static function sameSet(array $a, array $b): bool
    {
        $lower = fn (array $list): array => collect($list)->map(fn (string $email): string => mb_strtolower($email))->sort()->values()->all();

        return $lower(self::normalize($a)) === $lower(self::normalize($b));
    }

    /** `j***@dominio.com`: para logs; el correo completo sólo vive en el contexto cifrado del evento. */
    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***@'.$domain;
    }
}
