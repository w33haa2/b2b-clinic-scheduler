<?php

use App\Enums\ClinicRoleKey;
use App\Models\ClinicPermission;
use App\Models\ClinicRole;
use App\Models\ClinicUser;
use Laravel\Sanctum\Sanctum;

test('an admin creates a custom role with permissions', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $permission = ClinicPermission::query()->where('name', 'clinic.invoices.index')->firstOrFail();
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.roles.store', $admin->clinic_id), [
        'name' => 'Cashier',
        'permission_ids' => [$permission->id],
    ])->assertCreated()
        ->assertJsonPath('data.is_system', false)
        ->assertJsonPath('data.permissions', ['clinic.invoices.index']);
});

test('the worker role permissions can be changed', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $workerRole = $admin->clinic->clinicRoles()->where('key', ClinicRoleKey::Worker)->firstOrFail();
    $permission = ClinicPermission::query()->where('name', 'clinic.invoices.index')->firstOrFail();
    Sanctum::actingAs($admin->user);

    $this->putJson(route('clinic.roles.update', [$admin->clinic_id, $workerRole]), ['permission_ids' => [$permission->id]])
        ->assertOk()
        ->assertJsonPath('data.permissions', ['clinic.invoices.index']);
});

test('the worker role cannot be renamed', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $workerRole = $admin->clinic->clinicRoles()->where('key', ClinicRoleKey::Worker)->firstOrFail();
    Sanctum::actingAs($admin->user);

    $this->putJson(route('clinic.roles.update', [$admin->clinic_id, $workerRole]), ['name' => 'Staff'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name' => 'The Clinic Worker role cannot be renamed.']);
});

test('the admin role cannot be changed', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $adminRole = $admin->clinic->clinicRoles()->where('key', ClinicRoleKey::Admin)->firstOrFail();
    Sanctum::actingAs($admin->user);

    $this->putJson(route('clinic.roles.update', [$admin->clinic_id, $adminRole]), ['permission_ids' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role' => 'The Clinic Admin role cannot be changed.']);
});

test('the built-in roles cannot be deleted', function (ClinicRoleKey $key) {
    $admin = ClinicUser::factory()->admin()->create();
    $role = $admin->clinic->clinicRoles()->where('key', $key)->firstOrFail();
    Sanctum::actingAs($admin->user);

    $this->deleteJson(route('clinic.roles.destroy', [$admin->clinic_id, $role]))->assertUnprocessable();

    $this->assertModelExists($role);
})->with(ClinicRoleKey::cases());

test('a custom role is deleted and removed from its members', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $role = ClinicRole::factory()->for($admin->clinic)->create();
    $member = ClinicUser::factory()->withRole($role)->create();
    Sanctum::actingAs($admin->user);

    $this->deleteJson(route('clinic.roles.destroy', [$admin->clinic_id, $role]))->assertNoContent();

    $this->assertModelMissing($role);
    expect($member->clinicRoles()->count())->toBe(0);
});
