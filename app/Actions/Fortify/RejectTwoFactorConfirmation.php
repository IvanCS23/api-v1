<?php

namespace App\Actions\Fortify;

use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;

/**
 * Fase 6.31 — Confirmar la 2FA es el último paso de una activación nueva
 * (POST /user/confirmed-two-factor-authentication), así que también se
 * rechaza: una configuración que hubiera quedado a medias no llega a
 * activarse. Ver RejectNewTwoFactorAuthentication.
 */
final class RejectTwoFactorConfirmation extends ConfirmTwoFactorAuthentication
{
    public function __invoke($user, $code)
    {
        throw RejectNewTwoFactorAuthentication::rejection();
    }
}
