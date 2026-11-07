<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Fase 6.32 — Revisa la configuración del correo transaccional (enlaces de
 * recuperación de contraseña) sin enviar nada.
 *
 * Falla en producción si:
 * - el mailer es `log` (escribiría el correo completo, con el enlace y su
 *   token, en laravel.log) o `array` (no entrega nada);
 * - el remitente es el de ejemplo o falta;
 * - FRONTEND_URL apunta a localhost o no usa https (el enlace del correo).
 * - la cola es `sync` (la respuesta de "olvidé mi contraseña" tardaría
 *   distinto según exista o no el correo).
 *
 * Fuera de producción esos casos son avisos. Con --connect abre una conexión
 * TCP al servidor SMTP configurado y la cierra, sin enviar ningún correo
 * (sirve para comprobar Mailpit en desarrollo o el proveedor en P1).
 * Nunca imprime usuario ni contraseña del SMTP.
 */
class MailCheck extends Command
{
    /**
     * @var string
     */
    protected $signature = 'mail:check {--connect : Abre y cierra una conexión TCP al SMTP, sin enviar nada}';

    /**
     * @var string
     */
    protected $description = 'Revisa la configuración del correo transaccional sin enviar ningún correo.';

    public function handle(): int
    {
        $production = app()->isProduction();
        $mailer = (string) config('mail.default');
        $transport = (string) config("mail.mailers.{$mailer}.transport", $mailer);
        $from = (string) config('mail.from.address');
        $frontend = (string) config('app.frontend_url');
        $queue = (string) config('queue.default');
        $problems = [];

        $this->line("Mailer: {$mailer} (transporte {$transport})");

        if (in_array($transport, ['log', 'array'], true)) {
            $problems[] = $transport === 'log'
                ? 'El mailer es log: el correo completo, con el enlace de recuperación y su token, se escribiría en laravel.log.'
                : 'El mailer es array: ningún correo se entrega.';
        }

        if ($transport === 'smtp') {
            $host = (string) config("mail.mailers.{$mailer}.host");
            $port = (int) config("mail.mailers.{$mailer}.port");
            $this->line(sprintf('SMTP: %s:%d (esquema %s, usuario %s)', $host, $port, config("mail.mailers.{$mailer}.scheme") ?? 'automático', filled(config("mail.mailers.{$mailer}.username")) ? 'definido' : 'sin usuario'));

            if ($this->option('connect')) {
                $socket = @fsockopen($host, $port, $errno, $errstr, 5);

                if ($socket === false) {
                    $problems[] = "No se pudo conectar a {$host}:{$port} ({$errstr}).";
                } else {
                    fclose($socket);
                    $this->info("Conexión a {$host}:{$port}: OK (no se envió nada).");
                }
            }
        }

        $this->line('Remitente: '.($from !== '' ? $from : '(vacío)').' — '.config('mail.from.name'));

        if ($from === '' || str_ends_with($from, '@example.com')) {
            $problems[] = 'El remitente (MAIL_FROM_ADDRESS) falta o es el de ejemplo.';
        }

        $this->line("Enlaces hacia: {$frontend}");

        if (! str_starts_with($frontend, 'https://') || str_contains($frontend, 'localhost') || str_contains($frontend, '127.0.0.1')) {
            $problems[] = 'FRONTEND_URL no es una URL https pública: los enlaces de recuperación no funcionarían fuera de este equipo.';
        }

        $this->line("Cola: {$queue}");

        if ($queue === 'sync') {
            $problems[] = 'La cola es sync: la respuesta de recuperación tardaría distinto según exista o no el correo.';
        }

        if ($problems === []) {
            $this->info('Correo transaccional: OK.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $production ? $this->error($problem) : $this->warn($problem);
        }

        if ($production) {
            $this->error('Correo transaccional: FALLA (producción).');

            return self::FAILURE;
        }

        $this->warn('Correo transaccional: avisos (fuera de producción no fallan).');

        return self::SUCCESS;
    }
}
