<?php

use App\Actions\Fortify\RejectNewTwoFactorAuthentication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Fortify;

/**
 * Fase 6.31: la 2FA web no se puede activar de nuevo, ni llamando directo a
 * los endpoints de Fortify. Quien ya la tiene la puede desactivar y
 * completa el reto al iniciar sesión.
 */
function phase631TwoFactorUser(bool $confirmed = true): User
{
    $user = User::factory()->create();
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['codigo-recuperacion-1', 'codigo-recuperacion-2'])),
        'two_factor_confirmed_at' => $confirmed ? now() : null,
    ])->save();

    return $user->fresh();
}

test('activar la 2FA llamando directo al endpoint se rechaza y no guarda nada', function (bool $json) {
    $user = User::factory()->create();

    $request = $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    $response = $json
        ? $request->postJson('/user/two-factor-authentication')
        : $request->from('/settings/security')->post('/user/two-factor-authentication');

    $json
        ? $response->assertForbidden()->assertJsonPath('code', RejectNewTwoFactorAuthentication::CODE)
        : $response->assertRedirect('/settings/security')->assertSessionHasErrors(['two_factor' => RejectNewTwoFactorAuthentication::MESSAGE]);

    $fresh = $user->fresh();
    expect($fresh->two_factor_secret)->toBeNull()
        ->and($fresh->two_factor_recovery_codes)->toBeNull()
        ->and($fresh->two_factor_confirmed_at)->toBeNull();
})->with(['web' => false, 'json' => true]);

test('una configuración a medias no se puede confirmar', function () {
    $user = phase631TwoFactorUser(confirmed: false);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson('/user/confirmed-two-factor-authentication', ['code' => '123456'])
        ->assertForbidden()
        ->assertJsonPath('code', RejectNewTwoFactorAuthentication::CODE);

    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();
});

test('quien ya la tiene puede desactivarla', function () {
    $user = phase631TwoFactorUser();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->deleteJson('/user/two-factor-authentication')
        ->assertOk();

    $fresh = $user->fresh();
    expect($fresh->two_factor_secret)->toBeNull()
        ->and($fresh->two_factor_confirmed_at)->toBeNull();
});

test('quien ya la tiene completa el reto al iniciar sesión', function () {
    $user = phase631TwoFactorUser();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/two-factor-challenge');
    $this->assertGuest();

    $this->post('/two-factor-challenge', ['recovery_code' => 'codigo-recuperacion-1'])
        ->assertRedirect();

    $this->assertAuthenticatedAs($user);
    expect(json_decode(Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_recovery_codes), true))
        ->not->toContain('codigo-recuperacion-1');
});

test('volver a llamar a activar con la 2FA ya confirmada no la cambia', function () {
    $user = phase631TwoFactorUser();
    $secret = DB::table('users')->where('id', $user->id)->value('two_factor_secret');

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson('/user/two-factor-authentication')
        ->assertOk();

    expect(DB::table('users')->where('id', $user->id)->value('two_factor_secret'))->toBe($secret);
});
