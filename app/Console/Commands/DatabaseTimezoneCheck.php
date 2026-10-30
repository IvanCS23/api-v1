<?php

namespace App\Console\Commands;

use App\Support\Database\TimestampUtcNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.29 — Verifica (sólo lectura) que la sesión de MySQL/MariaDB
 * trabaja en UTC y que las fechas guardadas no quedaron corridas.
 *
 * Falla si la sesión no está en UTC o si alguna fecha reciente aparece en
 * el futuro: es el síntoma de leer en UTC datos escritos con la zona del
 * servidor sin haber corrido la migración normalize_timestamps_to_utc.
 */
class DatabaseTimezoneCheck extends Command
{
    /**
     * @var string
     */
    protected $signature = 'db:timezone-check
        {--tolerance=5 : Minutos de margen antes de considerar una fecha "en el futuro"}';

    /**
     * @var string
     */
    protected $description = 'Verifica que la sesión de la base de datos esté en UTC y que las fechas no estén corridas (sólo lectura).';

    /** Columnas que cambian con el uso normal: la más reciente nunca debería pasar de "ahora". */
    private const RECENT = [
        'invoices' => 'updated_at',
        'sales' => 'updated_at',
        'users' => 'updated_at',
        'invoice_pac_events' => 'occurred_at',
        'facturapi_organizations' => 'updated_at',
    ];

    public function handle(): int
    {
        $connection = DB::connection();

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->info("Conexión {$connection->getDriverName()}: la zona de sesión sólo aplica a MySQL/MariaDB.");

            return self::SUCCESS;
        }

        $now = CarbonImmutable::now('UTC');
        $session = $connection->selectOne('SELECT @@session.time_zone AS session_zone, @@global.time_zone AS global_zone, VERSION() AS version, TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()) AS session_offset');
        $legacyOffset = (new TimestampUtcNormalizer($connection))->legacyOffsetMinutes();
        $migrated = Schema::hasTable('migrations')
            && DB::table('migrations')->where('migration', '2026_10_31_010000_normalize_timestamps_to_utc')->exists();

        $this->table(['Dato', 'Valor'], [
            ['Servidor', $session->version],
            ['DB_TIMEZONE configurado', (string) config("database.connections.{$connection->getName()}.timezone", '(sin fijar)')],
            ['Zona de la sesión', $session->session_zone],
            ['Zona global del servidor', $session->global_zone],
            ['Desfase de la sesión (min)', (int) $session->session_offset],
            ['Desfase de la zona del servidor (min)', $legacyOffset],
            ['Migración normalize_timestamps_to_utc', $migrated ? 'aplicada' : 'pendiente'],
            ['Ahora (PHP, UTC)', $now->toDateTimeString()],
        ]);

        $failures = [];

        if ((int) $session->session_offset !== 0) {
            $failures[] = 'La sesión no está en UTC: fija DB_TIMEZONE=+00:00.';
        }

        $limit = $now->addMinutes((int) $this->option('tolerance'));
        $rows = [];

        foreach (self::RECENT as $table => $column) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $latest = DB::table($table)->max($column);
            $inFuture = $latest !== null && CarbonImmutable::parse($latest, 'UTC')->greaterThan($limit);
            $rows[] = ["{$table}.{$column}", $latest ?? '—', $inFuture ? 'EN EL FUTURO' : 'ok'];

            if ($inFuture) {
                $failures[] = "{$table}.{$column} tiene una fecha en el futuro ({$latest}): datos sin normalizar a UTC.";
            }
        }

        $this->line('Fecha más reciente, tal como la lee Laravel:');
        $this->table(['Columna', 'Más reciente', 'Estado'], $rows);

        if ($failures !== []) {
            foreach ($failures as $failure) {
                $this->error($failure);
            }

            return self::FAILURE;
        }

        $this->info('OK: sesión en UTC y ninguna fecha corrida.');

        return self::SUCCESS;
    }
}
