<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\User;
use App\Services\Users\CompanyAdminGuard;
use Illuminate\Support\Facades\Hash;

/**
 * Fase 6.27b (0): la gestión de usuarios es sólo para administradores
 * activos, nadie cambia su propio rol ni el de otro Admin, y la empresa
 * conserva al menos un Admin activo.
 */
function userAdminMember(Company $company, UserRole $role = UserRole::Admin, UserStatus $status = UserStatus::Active): User
{
    return User::factory()->create(['company_id' => $company->id, 'role' => $role, 'status' => $status]);
}

function userAdminNewUser(UserRole $role = UserRole::Admin): array
{
    return [
        'name' => 'Usuario nuevo',
        'email' => fake()->unique()->safeEmail(),
        'password' => 'contraseña-segura-1',
        'password_confirmation' => 'contraseña-segura-1',
        'role' => $role->value,
    ];
}

test('sólo un Admin puede crear, editar, desactivar y administrar la seguridad de usuarios', function (UserRole $role) {
    $company = Company::factory()->create();
    $actor = userAdminMember($company, $role);
    $admin = userAdminMember($company);
    $passwordBefore = $admin->password;

    $this->actingAs($actor, 'api');

    $this->postJson('/api/users', userAdminNewUser())->assertForbidden();
    $this->putJson("/api/users/{$admin->id}", ['name' => 'Cambiado'])->assertForbidden();
    $this->deleteJson("/api/users/{$admin->id}")->assertForbidden();
    $this->patchJson("/api/users/{$admin->id}/password", ['password' => 'otra-contraseña-1', 'password_confirmation' => 'otra-contraseña-1'])->assertForbidden();
    $this->postJson("/api/users/{$admin->id}/password/reset")->assertForbidden()->assertJsonMissingPath('temporary_password');
    $this->postJson("/api/users/{$admin->id}/sessions/revoke")->assertForbidden();
    $this->patchJson("/api/users/{$admin->id}/must-change-password", ['must_change_password' => true])->assertForbidden();

    $admin->refresh();
    expect(User::query()->where('company_id', $company->id)->count())->toBe(2)
        ->and($admin->name)->not->toBe('Cambiado')
        ->and($admin->status)->toBe(UserStatus::Active)
        ->and($admin->password)->toBe($passwordBefore)
        ->and($admin->must_change_password)->toBeFalse();
})->with([
    'Accountant' => [UserRole::Accountant],
    'Sales' => [UserRole::Sales],
    'Employee' => [UserRole::Employee],
]);

test('quien no es Admin recibe 403 antes de la validación, sin saber qué correos existen', function () {
    $company = Company::factory()->create();
    $employee = userAdminMember($company, UserRole::Employee);
    $admin = userAdminMember($company);

    $this->actingAs($employee, 'api');

    $this->postJson('/api/users', ['email' => $admin->email])->assertForbidden();
    $this->putJson("/api/users/{$employee->id}", ['email' => $admin->email])->assertForbidden();
});

test('un usuario que no es Admin no puede ascenderse editándose', function () {
    $company = Company::factory()->create();
    $employee = userAdminMember($company, UserRole::Employee);
    userAdminMember($company);

    $this->actingAs($employee, 'api')
        ->putJson("/api/users/{$employee->id}", ['role' => UserRole::Admin->value])
        ->assertForbidden();

    expect($employee->fresh()->role)->toBe(UserRole::Employee);
});

test('un Admin desactivado conserva su token pero ya no administra usuarios', function () {
    $company = Company::factory()->create();
    $inactive = userAdminMember($company, UserRole::Admin, UserStatus::Inactive);
    $employee = userAdminMember($company, UserRole::Employee);
    userAdminMember($company);

    $this->actingAs($inactive, 'api')
        ->putJson("/api/users/{$employee->id}", ['role' => UserRole::Admin->value])
        ->assertForbidden();

    expect($employee->fresh()->role)->toBe(UserRole::Employee);
});

test('un Admin activo crea usuarios, incluso administradores, y cambia el rol de quien no es Admin', function () {
    $company = Company::factory()->create();
    $admin = userAdminMember($company);
    $employee = userAdminMember($company, UserRole::Employee);

    $this->actingAs($admin, 'api');

    $this->postJson('/api/users', userAdminNewUser())->assertCreated()->assertJsonPath('user.role', 'admin');
    $this->putJson("/api/users/{$employee->id}", ['role' => UserRole::Accountant->value])->assertOk();

    expect($employee->fresh()->role)->toBe(UserRole::Accountant);

    $this->putJson("/api/users/{$employee->id}", ['role' => UserRole::Admin->value])->assertOk();

    expect($employee->fresh()->role)->toBe(UserRole::Admin);
});

test('nadie cambia su propio rol, aunque haya otro Admin', function () {
    $company = Company::factory()->create();
    $admin = userAdminMember($company);
    userAdminMember($company);

    $this->actingAs($admin, 'api')
        ->putJson("/api/users/{$admin->id}", ['role' => UserRole::Accountant->value])
        ->assertStatus(422)
        ->assertJsonPath('code', 'USER_SELF_ROLE_CHANGE');

    expect($admin->fresh()->role)->toBe(UserRole::Admin);
});

test('reenviar el mismo rol al editarse no es un cambio de rol', function () {
    $company = Company::factory()->create();
    $admin = userAdminMember($company);

    $this->actingAs($admin, 'api')
        ->putJson("/api/users/{$admin->id}", ['name' => 'Nombre nuevo', 'role' => UserRole::Admin->value, 'status' => UserStatus::Active->value])
        ->assertOk();

    expect($admin->fresh()->name)->toBe('Nombre nuevo');
});

test('nadie cambia su propio estado editándose', function () {
    $company = Company::factory()->create();
    $admin = userAdminMember($company);
    userAdminMember($company);

    $this->actingAs($admin, 'api')
        ->putJson("/api/users/{$admin->id}", ['status' => UserStatus::Suspended->value])
        ->assertStatus(422)
        ->assertJsonPath('code', 'USER_SELF_STATUS_CHANGE');

    expect($admin->fresh()->status)->toBe(UserStatus::Active);
});

test('un Admin no cambia el rol de otro Admin', function (UserRole $newRole) {
    $company = Company::factory()->create();
    $admin = userAdminMember($company);
    $other = userAdminMember($company);

    $this->actingAs($admin, 'api')
        ->putJson("/api/users/{$other->id}", ['role' => $newRole->value])
        ->assertStatus(422)
        ->assertJsonPath('code', 'USER_ADMIN_ROLE_LOCKED');

    expect($other->fresh()->role)->toBe(UserRole::Admin);
})->with([
    'Accountant' => [UserRole::Accountant],
    'Sales' => [UserRole::Sales],
    'Employee' => [UserRole::Employee],
]);

test('el último Admin activo no pierde su rol ni su estado', function (array $payload) {
    $company = Company::factory()->create();
    $admin = userAdminMember($company);
    userAdminMember($company, UserRole::Employee);
    userAdminMember($company, UserRole::Admin, UserStatus::Inactive);

    $this->actingAs($admin, 'api')
        ->putJson("/api/users/{$admin->id}", $payload)
        ->assertStatus(422)
        ->assertJsonPath('code', 'USER_LAST_ADMIN');

    $admin->refresh();
    expect($admin->role)->toBe(UserRole::Admin)
        ->and($admin->status)->toBe(UserStatus::Active);
})->with([
    'cambiar de rol' => [['role' => UserRole::Employee->value]],
    'desactivarse' => [['status' => UserStatus::Inactive->value]],
]);

test('un Admin desactiva a otro Admin, y éste ya no puede desactivar al que queda', function () {
    $company = Company::factory()->create();
    $first = userAdminMember($company);
    $second = userAdminMember($company);

    $this->actingAs($first, 'api')->deleteJson("/api/users/{$second->id}")->assertOk();

    expect($second->fresh()->status)->toBe(UserStatus::Inactive);

    // El desactivado conserva su token: la policy lo detiene.
    $this->actingAs($second->fresh(), 'api')->deleteJson("/api/users/{$first->id}")->assertForbidden();

    expect($first->fresh()->status)->toBe(UserStatus::Active);
});

test('el guard impide dejar a la empresa sin Admin activo aunque la policy no lo detuviera', function () {
    $company = Company::factory()->create();
    $last = userAdminMember($company);
    $inactive = userAdminMember($company, UserRole::Admin, UserStatus::Inactive);
    $applied = false;

    $violation = app(CompanyAdminGuard::class)->change($inactive, $last, null, UserStatus::Inactive, function () use (&$applied): void {
        $applied = true;
    });

    expect($violation['code'])->toBe('USER_LAST_ADMIN')
        ->and($applied)->toBeFalse();
});

test('un Admin no cambia ni restablece la contraseña de otro Admin', function () {
    $company = Company::factory()->create();
    $admin = userAdminMember($company);
    $other = userAdminMember($company);
    $passwordBefore = $other->password;

    $this->actingAs($admin, 'api');

    $this->postJson("/api/users/{$other->id}/password/reset")
        ->assertStatus(422)
        ->assertJsonPath('code', 'USER_ADMIN_PASSWORD_LOCKED')
        ->assertJsonMissingPath('temporary_password');
    $this->patchJson("/api/users/{$other->id}/password", ['password' => 'otra-contraseña-1', 'password_confirmation' => 'otra-contraseña-1'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'USER_ADMIN_PASSWORD_LOCKED');

    expect($other->fresh()->password)->toBe($passwordBefore);
});

test('un Admin restablece la contraseña de quien no es Admin y cambia la suya', function () {
    $company = Company::factory()->create();
    $admin = userAdminMember($company);
    $employee = userAdminMember($company, UserRole::Employee);

    $this->actingAs($admin, 'api');

    $temporary = $this->postJson("/api/users/{$employee->id}/password/reset")->assertOk()->json('temporary_password');
    $this->patchJson("/api/users/{$admin->id}/password", ['password' => 'mi-nueva-contraseña', 'password_confirmation' => 'mi-nueva-contraseña'])->assertOk();

    expect(Hash::check($temporary, $employee->fresh()->password))->toBeTrue()
        ->and(Hash::check('mi-nueva-contraseña', $admin->fresh()->password))->toBeTrue();
});

test('un Admin no administra usuarios de otra empresa', function () {
    $admin = userAdminMember(Company::factory()->create());
    $foreign = userAdminMember(Company::factory()->create(), UserRole::Employee);

    $this->actingAs($admin, 'api');

    $this->putJson("/api/users/{$foreign->id}", ['role' => UserRole::Admin->value])->assertNotFound();
    $this->postJson("/api/users/{$foreign->id}/password/reset")->assertNotFound();

    expect($foreign->fresh()->role)->toBe(UserRole::Employee);
});
