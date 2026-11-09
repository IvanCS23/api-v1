<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Fase 6.32: entorno de desarrollo sólo con PHP (`composer dev`). Antes
 * usaba `npx concurrently` y Vite; api-v1 ya no tiene JavaScript.
 *
 * Levanta en paralelo el servidor (`artisan serve`) y el worker de colas
 * (`artisan queue:listen --tries=1`), con la salida de cada uno marcada.
 * Si uno termina, detiene el otro. Ctrl+C detiene ambos.
 */
class Dev extends Command
{
    /**
     * @var string
     */
    protected $signature = 'dev {--host=127.0.0.1 : Interfaz del servidor} {--port=8000 : Puerto del servidor}';

    /**
     * @var string
     */
    protected $description = 'Levanta el servidor de desarrollo y el worker de colas (sólo PHP).';

    /**
     * Los procesos que levanta, con su etiqueta.
     *
     * @return array<string, list<string>>
     */
    public static function processes(string $host, string $port): array
    {
        $php = (new PhpExecutableFinder)->find(false) ?: 'php';

        return [
            'server' => [$php, 'artisan', 'serve', "--host={$host}", "--port={$port}"],
            'queue' => [$php, 'artisan', 'queue:listen', '--tries=1'],
        ];
    }

    public function handle(): int
    {
        /** @var array<string, Process> $running */
        $running = [];

        foreach (self::processes((string) $this->option('host'), (string) $this->option('port')) as $name => $command) {
            $process = new Process($command, base_path(), null, null, null);
            $process->start();
            $running[$name] = $process;
            $this->line("<info>[{$name}]</info> ".implode(' ', array_slice($command, 1)));
        }

        while (true) {
            foreach ($running as $name => $process) {
                foreach ([$process->getIncrementalOutput(), $process->getIncrementalErrorOutput()] as $output) {
                    foreach (preg_split('/\R/', rtrim($output)) ?: [] as $line) {
                        if ($line !== '') {
                            $this->line("<comment>[{$name}]</comment> {$line}");
                        }
                    }
                }

                if (! $process->isRunning()) {
                    $this->warn("[{$name}] terminó (código {$process->getExitCode()}); se detiene el resto.");

                    foreach ($running as $other) {
                        $other->stop(5);
                    }

                    return $process->getExitCode() === 0 ? self::SUCCESS : self::FAILURE;
                }
            }

            usleep(200_000);
        }
    }
}
