<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Settings\ProfileController;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

/**
 * Fase 6.31: desde la web sólo se edita el nombre; el correo de acceso lo
 * cambia un Admin por la API. El cambio de nombre queda auditado.
 */
test('el perfil web cambia sólo el nombre y lo audita; el correo enviado se ignora', function () {
    $user = User::factory()->create(['name' => 'Nombre anterior']);
    $email = $user->email;

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => 'Nombre nuevo', 'email' => 'otro@example.com'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();
    $audit = DB::table('audit_logs')->where('user_id', $user->id)->latest('id')->first();

    expect($user->name)->toBe('Nombre nuevo')
        ->and($user->email)->toBe($email)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($audit->action)->toBe('profile_updated')
        ->and((int) $audit->actor_id)->toBe($user->id)
        ->and(json_decode($audit->metadata, true))->toBe(['fields' => ['name'], 'previous_name' => 'Nombre anterior']);
});

test('guardar el mismo nombre no audita nada', function () {
    $user = User::factory()->create(['name' => 'Igual']);

    $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Igual'])->assertSessionHasNoErrors();

    expect(DB::table('audit_logs')->where('user_id', $user->id)->count())->toBe(0);
});

/**
 * Fase 6.31: nadie elimina su propia cuenta desde la web, igual que en la
 * API. Antes un usuario (incluido el último Admin activo) hacía aquí un
 * borrado suave de sí mismo sin CompanyAdminGuard.
 */
test('nadie puede eliminar su propia cuenta: el usuario queda intacto y activo', function (UserRole $role, bool $lastAdmin) {
    $company = Company::factory()->create();

    if (! $lastAdmin) {
        User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Admin]);
    }

    $user = User::factory()->create(['company_id' => $company->id, 'role' => $role]);
    $before = $user->fresh()->only(['id', 'role', 'status', 'email', 'deleted_at']);

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'password']);

    $response
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHasErrors(['account' => ProfileController::SELF_DELETION_MESSAGE]);

    $this->assertAuthenticatedAs($user);
    expect(User::withTrashed()->find($user->id)->only(['id', 'role', 'status', 'email', 'deleted_at']))->toBe($before)
        ->and($user->fresh()->status)->toBe(UserStatus::Active)
        ->and($user->fresh()->deleted_at)->toBeNull();
})->with([
    'admin con otro admin' => [UserRole::Admin, false],
    'último admin activo' => [UserRole::Admin, true],
    'contador' => [UserRole::Accountant, false],
    'ventas' => [UserRole::Sales, false],
    'empleado' => [UserRole::Employee, false],
]);

test('sin contraseña o con una JSON también se rechaza sin tocar al usuario', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->deleteJson(route('profile.destroy'))
        ->assertForbidden()
        ->assertJsonPath('code', ProfileController::SELF_DELETION_CODE);

    expect($user->fresh())->not->toBeNull()
        ->and($user->fresh()->status)->toBe(UserStatus::Active);
});
