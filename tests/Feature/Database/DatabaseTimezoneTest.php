<?php

use App\Support\Database\TimestampUtcNormalizer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.29: la sesión de MySQL/MariaDB trabaja en UTC (DB_TIMEZONE) y la
 * migración normalize_timestamps_to_utc reescribe lo guardado con la zona
 * del servidor. Las pruebas corren en sqlite; las que necesitan MySQL se
 * saltan salvo que la suite corra contra MySQL/MariaDB.
 */
function phase629IsMysql(): bool
{
    return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
}

test('las conexiones mysql y mariadb fijan la sesión en UTC por defecto', function () {
    expect(config('database.connections.mysql.timezone'))->toBe('+00:00')
        ->and(config('database.connections.mariadb.timezone'))->toBe('+00:00');
});

test('db:timezone-check no aplica fuera de MySQL/MariaDB y no falla', function () {
    if (phase629IsMysql()) {
        $this->markTestSkipped('Sólo para sqlite.');
    }

    expect(Artisan::call('db:timezone-check'))->toBe(0)
        ->and(Artisan::output())->toContain('sólo aplica a MySQL/MariaDB');
});

test('la migración no hace nada en sqlite', function () {
    if (phase629IsMysql()) {
        $this->markTestSkipped('Sólo para sqlite.');
    }

    expect(DB::table('migrations')->where('migration', '2026_10_31_010000_normalize_timestamps_to_utc')->exists())->toBeTrue();
});

test('MySQL: lo escrito con la zona del servidor se lee igual en UTC tras normalizar, y down() lo revierte', function () {
    if (! phase629IsMysql()) {
        $this->markTestSkipped('Requiere MySQL/MariaDB.');
    }

    $normalizer = new TimestampUtcNormalizer(DB::connection());

    if ($normalizer->legacyOffsetMinutes() === 0) {
        $this->markTestSkipped('La zona del servidor ya es UTC: no hay nada que normalizar.');
    }

    Schema::create('phase629_probe', function ($table): void {
        $table->id();
        $table->timestamp('at')->nullable();
    });

    try {
        DB::statement("SET time_zone = 'SYSTEM'");
        DB::table('phase629_probe')->insert([['at' => '2026-10-30 20:00:47'], ['at' => null]]);

        $normalizer->run(toUtc: true);
        DB::statement("SET time_zone = '+00:00'");
        expect(DB::table('phase629_probe')->orderBy('id')->pluck('at')->all())->toBe(['2026-10-30 20:00:47', null]);

        $normalizer->run(toUtc: false);
        DB::statement("SET time_zone = 'SYSTEM'");
        expect(DB::table('phase629_probe')->value('at'))->toBe('2026-10-30 20:00:47');
    } finally {
        DB::statement("SET time_zone = '+00:00'");
        Schema::dropIfExists('phase629_probe');
    }
});
