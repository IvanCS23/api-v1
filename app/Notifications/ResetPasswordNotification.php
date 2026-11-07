<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Fase 6.32 — Enlace de recuperación de contraseña hacia Next.js.
 *
 * - El token va después de `#` (D2): esa parte de la URL nunca se envía a
 *   ningún servidor, así que no queda en registros de acceso ni en el
 *   Referer. La página de restablecer lo lee y lo borra de la barra.
 * - Va en cola (ShouldQueue): la respuesta de POST /api/password/forgot
 *   tarda lo mismo exista o no el correo.
 * - Caduca en `auth.passwords.users.expire` minutos (30, D3) y sirve una
 *   sola vez.
 */
class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(#[\SensitiveParameter] public readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Restablece tu contraseña')
            ->greeting('Hola')
            ->line('Recibimos una solicitud para restablecer la contraseña de tu cuenta.')
            ->action('Restablecer contraseña', self::url($this->token, $notifiable->getEmailForPasswordReset()))
            ->line("El enlace vence en {$minutes} minutos y sólo funciona una vez.")
            ->line('Si no lo pediste, ignora este correo: tu contraseña no cambiará.')
            ->salutation('FacturaPro');
    }

    public static function url(#[\SensitiveParameter] string $token, string $email): string
    {
        return rtrim((string) config('app.frontend_url'), '/')
            .'/restablecer-contrasena#'
            .http_build_query(['token' => $token, 'email' => $email], '', '&', PHP_QUERY_RFC3986);
    }
}
