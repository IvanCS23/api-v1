<?php

namespace App\Support\Database;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Fase 6.29 — Reescribe las columnas TIMESTAMP de MySQL/MariaDB para que
 * la sesión pueda trabajar en UTC (`DB_TIMEZONE=+00:00`).
 *
 * Antes la sesión usaba la zona del servidor (`SYSTEM`). Laravel escribe
 * texto en UTC y MySQL lo interpretaba como hora local, así que guardaba
 * cada valor corrido (6 h con America/Mexico_City). Leer en `+00:00` sin
 * migrar desplazaría todas las fechas.
 *
 * Cada valor se lee con la sesión en `SYSTEM` (el texto exacto que Laravel
 * veía) y se vuelve a escribir con la sesión en `+00:00`. No usa
 * CONVERT_TZ ni desfases fijos, así que respeta cualquier cambio de horario
 * de la zona del servidor y no necesita las tablas de zonas de MySQL.
 * `down()` hace lo inverso.
 *
 * Si la zona del servidor ya es UTC no hay nada que corregir: un servidor
 * nuevo arranca con `DB_TIMEZONE=+00:00` y esto no hace nada.
 */
final class TimestampUtcNormalizer
{
    private const UTC = '+00:00';

    private const LEGACY = 'SYSTEM';

    private const CHUNK = 500;

    public function __construct(private readonly Connection $connection) {}

    /** Minutos que la zona del servidor difiere de UTC en este momento. */
    public function legacyOffsetMinutes(): int
    {
        return $this->withSessionZone(self::LEGACY, fn (): int => (int) $this->connection
            ->selectOne('SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()) AS offset_minutes')
            ->offset_minutes);
    }

    /**
     * Columnas TIMESTAMP de la base actual, por tabla, con su llave primaria.
     *
     * @return array<string, array{primary: list<string>, columns: list<string>}>
     */
    public function plan(): array
    {
        $database = $this->connection->getDatabaseName();
        $plan = [];

        $columns = $this->connection->select(
            "SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND DATA_TYPE = 'timestamp' ORDER BY TABLE_NAME, ORDINAL_POSITION",
            [$database],
        );

        foreach ($columns as $column) {
            $plan[$column->t]['columns'][] = $column->c;
        }

        foreach (array_keys($plan) as $table) {
            $primary = array_map(fn (object $row): string => $row->c, $this->connection->select(
                "SELECT COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION",
                [$database, $table],
            ));

            if ($primary === []) {
                throw new RuntimeException("La tabla {$table} tiene columnas TIMESTAMP pero no llave primaria; no se puede normalizar fila por fila.");
            }

            $plan[$table]['primary'] = $primary;
        }

        return $plan;
    }

    /**
     * De `SYSTEM` a UTC (up) o de UTC a `SYSTEM` (down). Devuelve las filas
     * reescritas por tabla. No hace nada si la zona del servidor es UTC.
     *
     * @return array<string, int>
     */
    public function run(bool $toUtc = true): array
    {
        if ($this->legacyOffsetMinutes() === 0) {
            return [];
        }

        [$readZone, $writeZone] = $toUtc ? [self::LEGACY, self::UTC] : [self::UTC, self::LEGACY];
        $plan = $this->plan();
        $rewritten = [];

        $this->connection->transaction(function () use ($plan, $readZone, $writeZone, &$rewritten): void {
            foreach ($plan as $table => $definition) {
                $rewritten[$table] = $this->rewriteTable($table, $definition['primary'], $definition['columns'], $readZone, $writeZone);
            }
        });

        return $rewritten;
    }

    /**
     * @param  list<string>  $primary
     * @param  list<string>  $columns
     */
    private function rewriteTable(string $table, array $primary, array $columns, string $readZone, string $writeZone): int
    {
        $count = 0;
        $offset = 0;

        while (true) {
            // Lectura en la zona de origen: el texto exacto que veía Laravel.
            $rows = $this->withSessionZone($readZone, fn (): array => $this->connection->table($table)
                ->select([...$primary, ...$columns])
                ->orderBy($primary[0])
                ->when(count($primary) > 1, fn ($query) => $query->orderBy($primary[1]))
                ->offset($offset)
                ->limit(self::CHUNK)
                ->get()
                ->all());

            if ($rows === []) {
                return $count;
            }

            // Escritura en la zona de destino.
            $this->withSessionZone($writeZone, function () use ($table, $primary, $columns, $rows, &$count): void {
                foreach ($rows as $row) {
                    $values = [];

                    foreach ($columns as $column) {
                        if ($row->{$column} !== null) {
                            $values[$column] = $row->{$column};
                        }
                    }

                    if ($values === []) {
                        continue;
                    }

                    $query = $this->connection->table($table);

                    foreach ($primary as $key) {
                        $query->where($key, $row->{$key});
                    }

                    $query->update($values);
                    $count++;
                }
            });

            $offset += self::CHUNK;
        }
    }

    /**
     * Ejecuta $callback con la sesión en $zone y restaura la zona anterior.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withSessionZone(string $zone, callable $callback): mixed
    {
        $previous = $this->connection->selectOne('SELECT @@session.time_zone AS zone')->zone;
        $this->connection->statement('SET time_zone = ?', [$zone]);

        try {
            return $callback();
        } finally {
            $this->connection->statement('SET time_zone = ?', [$previous]);
        }
    }
}
