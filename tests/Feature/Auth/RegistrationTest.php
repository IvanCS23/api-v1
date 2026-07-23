<?php

use App\Models\User;

/*
|--------------------------------------------------------------------------
| Registro público deshabilitado (decisión de arquitectura)
|--------------------------------------------------------------------------
|
| users.company_id es obligatorio y todavía no existe un flujo de onboarding
| que cree la Company y su usuario propietario. Mientras eso no exista, el
| registro público de Fortify permanece deshabilitado (config/fortify.php),
| y estas pruebas protegen esa decisión: si alguien reactiva la feature sin
| resolver el onboarding, este archivo debe volver a fallar.
|
*/

test('public registration screen is not available', function () {
    $response = $this->get('/register');

    $response->assertNotFound();
});

test('public registration endpoint does not create a user', function () {
    $usersBefore = User::count();

    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertNotFound();
    $this->assertGuest();
    expect(User::count())->toBe($usersBefore);
});
