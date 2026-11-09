<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Services\Users\TemporaryPassword;
use App\Services\Users\TemporaryPasswordUnavailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Fase 6.32 (corrección 5): toda contraseña temporal sigue las mismas reglas
 * que el cambio de contraseña: la que escribe el Admin al crear un usuario y
 * la que genera el sistema al restablecerla.
 */
function phase632TempAdmin(): User
{
    return User::factory()->create(['company_id' => Company::factory()->create()->id, 'role' => UserRole::Admin]);
}

function phase632CreateWith(User $admin, string $password)
{
    return test()->actingAs($admin, 'api')->postJson('/api/users', [
        'name' => 'Persona Temporal',
        'email' => 'temporal-'.uniqid().'@example.com',
        'password' => $password,
        'password_confirmation' => $password,
        'role' => UserRole::Sales->value,
        'must_change_password' => true,
    ]);
}

/** Reglas de producción reales; el servicio de filtradas responde "no aparece". */
function phase632ProductionRules(): void
{
    app()['env'] = 'production';
    Http::preventStrayRequests();
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);
}

test('la contraseña que genera el sistema cumple las reglas de producción', function () {
    phase632ProductionRules();

    foreach (range(1, 200) as $i) {
        $password = TemporaryPassword::generate();

        expect(strlen($password))->toBe(TemporaryPassword::LENGTH)
            ->and(Validator::make(['password' => $password], ['password' => [Password::default()]])->passes())->toBeTrue();
    }
});

test('el restablecimiento por el Admin entrega una temporal que cumple las reglas y sirve para entrar', function () {
    phase632ProductionRules();
    $admin = phase632TempAdmin();
    $user = User::factory()->create(['company_id' => $admin->company_id, 'role' => UserRole::Sales]);

    $temporary = $this->actingAs($admin, 'api')->postJson("/api/users/{$user->id}/password/reset")
        ->assertOk()
        ->assertJsonPath('user.must_change_password', true)
        ->json('temporary_password');

    expect(Validator::make(['password' => $temporary], ['password' => [Password::default()]])->passes())->toBeTrue()
        ->and(Hash::check($temporary, $user->fresh()->password))->toBeTrue();
});

test('al crear un usuario, la contraseña temporal sigue las reglas de producción', function (string $password, ?string $error) {
    phase632ProductionRules();
    $response = phase632CreateWith(phase632TempAdmin(), $password);

    $error === null
        ? $response->assertCreated()->assertJsonPath('user.must_change_password', true)
        : $response->assertUnprocessable()->assertJsonPath('errors.password.0', $error);
})->with([
    '8 caracteres' => ['Abc-1234', 'La contraseña debe tener al menos 12 caracteres.'],
    'sin mayúsculas' => ['abcdef-12345', 'La contraseña debe tener mayúsculas y minúsculas.'],
    'sin símbolos' => ['Abcdefg12345', 'La contraseña debe tener al menos un símbolo.'],
    'sin números' => ['Abcdef-ghijk', 'La contraseña debe tener al menos un número.'],
    '12 que cumple' => ['Abcdef-12345', null],
]);

test('fuera de producción crear un usuario usa las mismas reglas que el resto (Password::default)', function () {
    phase632CreateWith(phase632TempAdmin(), 'Ab-1234')
        ->assertUnprocessable()
        ->assertJsonPath('errors.password.0', 'La contraseña debe tener al menos 8 caracteres.');
});

test('el generador se rinde tras 5 intentos con una excepción controlada, sin ciclo sin fin', function () {
    $calls = 0;
    Password::defaults(function () use (&$calls) {
        $calls++;

        return Password::min(100);
    });

    expect(fn () => TemporaryPassword::generate())->toThrow(TemporaryPasswordUnavailable::class)
        ->and($calls)->toBe(TemporaryPassword::MAX_ATTEMPTS)
        ->and(TemporaryPassword::MAX_ATTEMPTS)->toBe(5);
});

test('si no logra una temporal válida, el restablecimiento responde 503 controlado y no cambia nada', function () {
    $admin = phase632TempAdmin();
    $user = User::factory()->create(['company_id' => $admin->company_id, 'role' => UserRole::Sales, 'must_change_password' => false]);
    $user->forceFill(['api_token' => hash('sha256', 'token-intacto')])->save();
    $before = $user->fresh();
    Password::defaults(fn () => Password::min(100));

    $this->actingAs($admin, 'api')->postJson("/api/users/{$user->id}/password/reset")
        ->assertStatus(503)
        ->assertJsonPath('code', 'TEMPORARY_PASSWORD_UNAVAILABLE')
        ->assertJsonMissingPath('temporary_password');

    $after = $user->fresh();
    expect($after->password)->toBe($before->password)
        ->and($after->api_token)->toBe($before->api_token)
        ->and($after->must_change_password)->toBeFalse()
        ->and(DB::table('audit_logs')->where('user_id', $user->id)->where('action', 'password_reset')->exists())->toBeFalse();
});
