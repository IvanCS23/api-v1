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
use Illuminate\Support\Facades\Password;

/**
 * Fase 6.31: todo cambio real de contraseña (web, restablecimiento por
 * correo y API) revoca el api_token, fija password_changed_at, cierra las
 * sesiones web y queda auditado. Un re-hash al iniciar sesión no.
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

test('el cambio de contraseña web revoca el token, fija la fecha, cierra las demás sesiones y audita', function () {
    config(['session.driver' => 'database']);
    $user = phase631UserWithToken(['must_change_password' => true]);
    $other = User::factory()->create();
    phase631SessionRows($user, ['sesion-otro-dispositivo-1', 'sesion-otro-dispositivo-2']);
    phase631SessionRows($other, ['sesion-de-otro-usuario']);

    $this->actingAs($user)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'nueva-Contrasena-2026',
            'password_confirmation' => 'nueva-Contrasena-2026',
        ])
        ->assertSessionHasNoErrors();

    $fresh = $user->fresh();
    $audit = DB::table('audit_logs')->where('user_id', $user->id)->latest('id')->first();

    expect(Hash::check('nueva-Contrasena-2026', $fresh->password))->toBeTrue()
        ->and($fresh->api_token)->toBeNull()
        ->and($fresh->password_changed_at->toDateTimeString())->toBe('2026-11-06 12:00:00')
        ->and($fresh->must_change_password)->toBeFalse()
        ->and(DB::table('sessions')->whereIn('id', ['sesion-otro-dispositivo-1', 'sesion-otro-dispositivo-2'])->count())->toBe(0)
        ->and(DB::table('sessions')->where('id', 'sesion-de-otro-usuario')->exists())->toBeTrue()
        ->and($audit->action)->toBe('password_changed')
        ->and((int) $audit->actor_id)->toBe($user->id)
        ->and(json_decode($audit->metadata, true))->toBe(['source' => 'web']);
    $this->assertAuthenticatedAs($user);
});

test('el restablecimiento por correo revoca el token, fija la fecha, cierra todas las sesiones y audita', function () {
    config(['session.driver' => 'database']);
    $user = phase631UserWithToken();
    phase631SessionRows($user, ['sesion-a', 'sesion-b']);
    $token = Password::broker()->createToken($user);

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'restablecida-2026',
        'password_confirmation' => 'restablecida-2026',
    ])->assertSessionHasNoErrors();

    $fresh = $user->fresh();
    $audit = DB::table('audit_logs')->where('user_id', $user->id)->latest('id')->first();

    expect(Hash::check('restablecida-2026', $fresh->password))->toBeTrue()
        ->and($fresh->api_token)->toBeNull()
        ->and($fresh->password_changed_at->toDateTimeString())->toBe('2026-11-06 12:00:00')
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and($audit->action)->toBe('password_changed')
        ->and($audit->actor_id)->toBeNull()
        ->and(json_decode($audit->metadata, true))->toBe(['source' => 'web_password_reset']);
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
