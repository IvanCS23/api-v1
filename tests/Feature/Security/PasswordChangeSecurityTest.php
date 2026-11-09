<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\User;
use App\Services\Users\PasswordChangeSecurity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Fase 6.31: todo cambio real de contraseña revoca el api_token, fija
 * password_changed_at, cierra las sesiones web y queda auditado. Un re-hash
 * al iniciar sesión no. (Fase 6.32: sin web; los caminos que quedan son los
 * de la API, que también cubre tests/Feature/Users.)
 */
function phase631SessionRows(User $user, array $ids): void
{
    foreach ($ids as $id) {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'x', 'payload' => 'x', 'last_activity' => time()]);
    }
}

function phase631UserWithToken(array $attributes = []): User
{
    $user = User::factory()->create(array_merge(['password_changed_at' => '2026-01-01 00:00:00'], $attributes));
    $user->forceFill(['api_token' => hash('sha256', 'token-'.$user->id)])->save();

    return $user->fresh();
}

beforeEach(function () {
    Carbon::setTestNow('2026-11-06 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

test('un re-hash de la contraseña no revoca el token ni cambia password_changed_at', function () {
    $user = phase631UserWithToken();
    $before = $user->password;

    Auth::guard('web')->getProvider()->rehashPasswordIfRequired($user, ['password' => 'password'], force: true);

    $fresh = $user->fresh();

    expect($fresh->password)->not->toBe($before)
        ->and(Hash::check('password', $fresh->password))->toBeTrue()
        ->and($fresh->api_token)->toBe(hash('sha256', 'token-'.$user->id))
        ->and($fresh->password_changed_at->toDateTimeString())->toBe('2026-01-01 00:00:00');
});

test('iniciar sesión por la API con un hash que se re-hashea no cambia password_changed_at', function () {
    // Configuración con más rondas que el hash guardado: el login lo re-hashea.
    config(['hashing.bcrypt.rounds' => 6]);
    app('hash')->forgetDrivers();
    $user = phase631UserWithToken();
    $user->forceFill(['password' => Hash::make('password', ['rounds' => 5])])->saveQuietly();
    expect(Hash::needsRehash($user->fresh()->password))->toBeTrue();

    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertOk();

    expect(Hash::needsRehash($user->fresh()->password))->toBeFalse()
        ->and($user->fresh()->password_changed_at->toDateTimeString())->toBe('2026-01-01 00:00:00');
});

test('los cambios por la API también cierran las sesiones web', function (string $action) {
    config(['session.driver' => 'database']);
    $company = Company::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Admin]);
    $user = phase631UserWithToken(['company_id' => $company->id, 'role' => UserRole::Sales]);
    phase631SessionRows($user, ['sesion-web-1']);

    $request = match ($action) {
        'cambio' => fn () => $this->actingAs($admin, 'api')->patchJson("/api/users/{$user->id}/password", ['password' => 'cambiada-2026A', 'password_confirmation' => 'cambiada-2026A']),
        'restablecimiento' => fn () => $this->actingAs($admin, 'api')->postJson("/api/users/{$user->id}/password/reset"),
        'cerrar sesiones' => fn () => $this->actingAs($admin, 'api')->postJson("/api/users/{$user->id}/sessions/revoke"),
    };

    $request()->assertOk();

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and($user->fresh()->api_token)->toBeNull();
})->with(['cambio', 'restablecimiento', 'cerrar sesiones']);

test('con un driver de sesión que no es database no falla y lo informa', function () {
    config(['session.driver' => 'array']);
    $user = User::factory()->create();

    expect(PasswordChangeSecurity::terminateWebSessions($user))->toBeNull();
});

test('un Admin único puede corregir su propio correo por la API', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Admin, 'status' => UserStatus::Active]);

    $this->actingAs($admin, 'api')
        ->putJson("/api/users/{$admin->id}", ['email' => 'nuevo-correo@example.com'])
        ->assertOk();

    expect($admin->fresh()->email)->toBe('nuevo-correo@example.com')
        ->and(User::query()->where('company_id', $company->id)->where('role', UserRole::Admin)->count())->toBe(1);
});
