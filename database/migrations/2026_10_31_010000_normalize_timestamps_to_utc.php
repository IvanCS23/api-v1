<?php

use App\Support\Database\TimestampUtcNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 6.29 — Fechas en UTC dentro de MySQL/MariaDB.
 *
 * Acompaña a `'timezone' => env('DB_TIMEZONE', '+00:00')` en
 * config/database.php: reescribe todas las columnas TIMESTAMP guardadas
 * mientras la sesión usaba la zona del servidor (ver
 * TimestampUtcNormalizer). Lee y escribe con la zona de sesión explícita,
 * así que no depende de la configuración con la que corra.
 *
 * - En un servidor cuya zona ya es UTC (o uno nuevo) no hace nada.
 * - En sqlite (pruebas) no aplica.
 * - Debe correr en el mismo servidor donde se escribieron los datos, con
 *   la aplicación en mantenimiento y el worker detenido: una escritura
 *   hecha con la sesión ya en UTC antes de esta migración quedaría
 *   corrida. Ver DEPLOYMENT.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->normalizer()?->run(toUtc: true);
    }

    public function down(): void
    {
        $this->normalizer()?->run(toUtc: false);
    }

    private function normalizer(): ?TimestampUtcNormalizer
    {
        $connection = DB::connection();

        return in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)
            ? new TimestampUtcNormalizer($connection)
            : null;
    }
};
