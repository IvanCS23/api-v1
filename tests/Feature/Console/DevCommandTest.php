<?php

use App\Console\Commands\Dev;
use Illuminate\Support\Facades\Artisan;

/**
 * Fase 6.32: `composer dev` sólo con PHP. El comando levanta el servidor y
 * el worker de colas; ya no hay Vite ni npx.
 */
test('levanta el servidor y el worker de colas, sin Node', function () {
    $processes = Dev::processes('127.0.0.1', '8123');

    expect(array_keys($processes))->toBe(['server', 'queue'])
        ->and(array_slice($processes['server'], 1))->toBe(['artisan', 'serve', '--host=127.0.0.1', '--port=8123'])
        ->and(array_slice($processes['queue'], 1))->toBe(['artisan', 'queue:listen', '--tries=1'])
        ->and(json_encode($processes))->not->toContain('npm')
        ->and(json_encode($processes))->not->toContain('npx');
});

test('composer dev llama a artisan dev y ningún script de composer usa npm', function () {
    $scripts = json_decode(file_get_contents(base_path('composer.json')), true)['scripts'];

    expect($scripts['dev'])->toContain('@php artisan dev')
        ->and(json_encode($scripts))->not->toContain('npm')
        ->and(json_encode($scripts))->not->toContain('npx')
        ->and(Artisan::all())->toHaveKey('dev');
});
