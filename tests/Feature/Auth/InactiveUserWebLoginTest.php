<?php

use App\Enums\UserStatus;
use App\Http\Middleware\EnsureUserIsActive;
use App\Models\User;
use Laravel\Fortify\Features;

/**
 * Fase 6.29: el login web de Fortify rechaza a un usuario que no está
 * activo, igual que POST /api/login. El estado sólo se revela con la
 * contraseña correcta.
 */
test('un usuario no activo no inicia sesión en la web y recibe el aviso de cuenta desactivada', function (UserStatus $status) {
    $user = User::factory()->create(['status' => $status]);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => EnsureUserIsActive::MESSAGE]);

    $this->assertGuest();
})->with([
    'inactivo' => [UserStatus::Inactive],
    'suspendido' => [UserStatus::Suspended],
]);

test('con la contraseña incorrecta un usuario no activo recibe el error genérico, no su estado', function () {
    $user = User::factory()->create(['status' => UserStatus::Inactive]);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'otra-contraseña'])
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);

    $this->assertGuest();
});

test('un usuario activo sigue iniciando sesión en la web', function () {
    $user = User::factory()->create(['status' => UserStatus::Active]);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('con 2FA, un usuario no activo no llega al reto del código', function () {
    $this->skipUnlessFortifyFeature(Features::twoFactorAuthentication());

    $user = User::factory()->create(['status' => UserStatus::Inactive]);
    $user->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => EnsureUserIsActive::MESSAGE])
        ->assertSessionMissing('login.id');

    $this->assertGuest();
});
