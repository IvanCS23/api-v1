<?php

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\User;
use App\Policies\PaymentPolicy;
use App\Services\Users\RoleService;
use Tests\TestCase;

// Sin base de datos: RoleService solo lee el rol del modelo en memoria.
uses(TestCase::class);

/** @return list<string> */
function roleServiceValues(array $permissions): array
{
    $values = array_map(fn (Permission $permission): string => $permission->value, $permissions);
    sort($values);

    return $values;
}

test('cada caso de UserRole tiene permisos definidos', function (UserRole $role) {
    // Recorre cases(): un rol nuevo sin mapear en RoleService lanza
    // UnhandledMatchError aquí.
    expect(app(RoleService::class)->permissionsFor($role))->not->toBeEmpty();
})->with(fn () => array_map(fn (UserRole $role) => [$role], UserRole::cases()));

test('Admin tiene todos los permisos', function () {
    expect(roleServiceValues(app(RoleService::class)->permissionsFor(UserRole::Admin)))
        ->toBe(roleServiceValues(Permission::cases()));
});

test('Accountant tiene todos los permisos menos users.manage', function () {
    $expected = array_values(array_filter(Permission::cases(), fn (Permission $p): bool => $p !== Permission::UsersManage));

    expect(roleServiceValues(app(RoleService::class)->permissionsFor(UserRole::Accountant)))
        ->toBe(roleServiceValues($expected))
        ->toContain(Permission::PaymentsView->value, Permission::PaymentsManage->value);
});

test('Sales y Employee tienen todos los permisos menos users.manage y payments.*', function (UserRole $role) {
    $expected = array_values(array_filter(
        Permission::cases(),
        fn (Permission $p): bool => $p !== Permission::UsersManage && ! str_starts_with($p->value, 'payments.'),
    ));

    expect(roleServiceValues(app(RoleService::class)->permissionsFor($role)))
        ->toBe(roleServiceValues($expected))
        ->not->toContain(Permission::UsersManage->value)
        ->not->toContain(Permission::PaymentsView->value)
        ->not->toContain(Permission::PaymentsManage->value);
})->with([
    'Sales' => [UserRole::Sales],
    'Employee' => [UserRole::Employee],
]);

test('Client solo tiene permisos *.view, sin payments.view', function () {
    $permissions = app(RoleService::class)->permissionsFor(UserRole::Client);
    $expected = array_values(array_filter(
        Permission::cases(),
        fn (Permission $p): bool => str_ends_with($p->value, '.view') && $p !== Permission::PaymentsView,
    ));

    expect(roleServiceValues($permissions))->toBe(roleServiceValues($expected))
        ->not->toContain(Permission::PaymentsView->value);

    foreach ($permissions as $permission) {
        expect($permission->value)->toEndWith('.view');
    }
});

test('los permisos de pagos del catálogo coinciden con PaymentPolicy', function (UserRole $role) {
    // PaymentPolicy no se toca: su regla (Admin y Accountant) es la fuente.
    $roles = app(RoleService::class);
    $policy = app(PaymentPolicy::class);
    $user = new User(['role' => $role->value]);

    expect($roles->roleAllows($role, Permission::PaymentsView))->toBe($policy->viewAny($user))
        ->and($roles->roleAllows($role, Permission::PaymentsManage))->toBe($policy->create($user))
        ->and($policy->viewAny($user))->toBe(in_array($role, [UserRole::Admin, UserRole::Accountant], true));
})->with(fn () => array_map(fn (UserRole $role) => [$role], UserRole::cases()));

test('allows usa el rol del usuario', function () {
    $roles = app(RoleService::class);
    $client = new User(['role' => UserRole::Client->value]);
    $sales = new User(['role' => UserRole::Sales->value]);

    expect($roles->allows($client, Permission::ProductsView))->toBeTrue()
        ->and($roles->allows($client, Permission::ProductsManage))->toBeFalse()
        ->and($roles->allows($sales, Permission::ProductsManage))->toBeTrue()
        ->and($roles->allows($sales, Permission::UsersManage))->toBeFalse();
});

test('allows niega todo a un usuario sin rol', function () {
    $roles = app(RoleService::class);
    $user = new User;

    expect($user->role)->toBeNull();

    foreach (Permission::cases() as $permission) {
        expect($roles->allows($user, $permission))->toBeFalse();
    }
});

test('label de cada rol', function (UserRole $role, string $label) {
    expect($role->label())->toBe($label);
})->with([
    [UserRole::Admin, 'Administrador'],
    [UserRole::Accountant, 'Contador'],
    [UserRole::Sales, 'Ventas'],
    [UserRole::Employee, 'Empleado'],
    [UserRole::Client, 'Cliente'],
]);

test('el catálogo trae los 5 roles con etiqueta y permisos', function () {
    $catalog = app(RoleService::class)->catalog();

    expect(array_column($catalog, 'value'))->toBe(['admin', 'accountant', 'sales', 'employee', 'client'])
        ->and(array_column($catalog, 'label'))->toBe(['Administrador', 'Contador', 'Ventas', 'Empleado', 'Cliente'])
        ->and($catalog[0]['permissions'])->toHaveCount(count(Permission::cases()));
});
