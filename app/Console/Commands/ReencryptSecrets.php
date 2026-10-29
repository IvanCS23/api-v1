<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;

/**
 * Fase 6.27b — Vuelve a cifrar con la APP_KEY actual los valores que
 * todavía están cifrados con una llave de APP_PREVIOUS_KEYS, para poder
 * retirarla después de una rotación (ver BILLING_LIFECYCLE.md).
 *
 * Columnas: facturapi_organizations.test_secret_key,
 * invoices.pac_response, invoices.pac_draft_response e
 * invoice_pac_events.context. Todas usan el mismo formato (cast
 * `encrypted` / `encrypted:array` = encryptString).
 *
 * Por cada valor: si descifra con la llave actual no se toca; si sólo
 * descifra con una anterior, se vuelve a cifrar con la actual y se
 * comprueba la ida y vuelta (mismo texto) antes de escribir; si no
 * descifra con ninguna se reporta y no se toca. La escritura es por
 * DB::table, sin eventos ni `updated_at`, y sólo si el valor no cambió
 * desde que se leyó.
 *
 * Excepción documentada: invoice_pac_events es append-only en el modelo
 * (InvoicePacEvent prohíbe `updating`). Aquí se reescribe el cifrado de
 * `context` sin cambiar su contenido; si no, esos eventos quedarían
 * ilegibles al retirar la llave anterior.
 *
 * Sin --apply sólo cuenta. Nunca imprime valores.
 */
class ReencryptSecrets extends Command
{
    /** @var array<string, list<string>> */
    public const COLUMNS = [
        'facturapi_organizations' => ['test_secret_key'],
        'invoices' => ['pac_response', 'pac_draft_response'],
        'invoice_pac_events' => ['context'],
    ];

    /**
     * @var string
     */
    protected $signature = 'security:reencrypt
        {--apply : Vuelve a cifrar; sin esta opción sólo cuenta}
        {--chunk=200 : Filas por lote}';

    /**
     * @var string
     */
    protected $description = 'Vuelve a cifrar con la APP_KEY actual los valores cifrados con una llave anterior (APP_PREVIOUS_KEYS).';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $chunk = max(1, (int) $this->option('chunk'));

        /** @var Encrypter $encrypter */
        $encrypter = app('encrypter');
        $current = new Encrypter($encrypter->getKey(), (string) config('app.cipher'));
        $previousKeys = count($encrypter->getPreviousKeys());

        $this->info('Cifrado de columnas sensibles'.($apply ? '' : ' — dry-run'));
        $this->line("Llaves anteriores configuradas (APP_PREVIOUS_KEYS): {$previousKeys}");

        $rows = [];
        $unreadableTotal = 0;
        $failedTotal = 0;

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $counts = $this->process($table, $column, $current, $encrypter, $apply, $chunk);
                $unreadableTotal += $counts['unreadable'];
                $failedTotal += $counts['failed'];
                $rows[] = ["{$table}.{$column}", $counts['current'], $counts['previous'], $counts['reencrypted'], $counts['unreadable'], $counts['failed']];
            }
        }

        $this->table(['Columna', 'Llave actual', 'Llave anterior', 'Recifrados', 'Ilegibles', 'Sin escribir'], $rows);

        if (! $apply) {
            $this->warn('Dry-run: no se escribió nada. Repite con --apply para volver a cifrar.');
        }

        if ($unreadableTotal > 0) {
            $this->error("{$unreadableTotal} valores no se pueden descifrar con ninguna llave configurada; no se tocaron.");
        }

        if ($failedTotal > 0) {
            $this->error("{$failedTotal} valores no se escribieron (cambiaron durante el proceso o falló la comprobación); repite el comando.");
        }

        return $unreadableTotal > 0 || $failedTotal > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array{current: int, previous: int, reencrypted: int, unreadable: int, failed: int} */
    private function process(string $table, string $column, Encrypter $current, Encrypter $withPrevious, bool $apply, int $chunk): array
    {
        $counts = ['current' => 0, 'previous' => 0, 'reencrypted' => 0, 'unreadable' => 0, 'failed' => 0];

        DB::table($table)
            ->select(['id', $column])
            ->whereNotNull($column)
            ->orderBy('id')
            ->chunkById($chunk, function ($rows) use ($table, $column, $current, $withPrevious, $apply, &$counts): void {
                foreach ($rows as $row) {
                    $payload = (string) $row->{$column};

                    if ($this->decrypts($current, $payload)) {
                        $counts['current']++;

                        continue;
                    }

                    try {
                        $plain = $withPrevious->decryptString($payload);
                    } catch (DecryptException) {
                        $counts['unreadable']++;

                        continue;
                    }

                    $counts['previous']++;

                    if (! $apply) {
                        continue;
                    }

                    $reencrypted = $current->encryptString($plain);

                    // Ida y vuelta antes de escribir: mismo texto con la llave actual.
                    if ($current->decryptString($reencrypted) !== $plain) {
                        $counts['failed']++;

                        continue;
                    }

                    $updated = DB::table($table)
                        ->where('id', $row->id)
                        ->where($column, $payload)
                        ->update([$column => $reencrypted]);

                    $updated === 1 ? $counts['reencrypted']++ : $counts['failed']++;
                }
            });

        return $counts;
    }

    private function decrypts(Encrypter $encrypter, string $payload): bool
    {
        try {
            $encrypter->decryptString($payload);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
}
