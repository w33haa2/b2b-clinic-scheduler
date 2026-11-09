<?php

use App\Models\ClinicUser;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('clinic members cannot reach the internal api whatever their role', function (string $role) {
    Sanctum::actingAs(ClinicUser::factory()->{$role}()->create()->user);

    $this->getJson(route('internal.super-admins.index'))->assertForbidden();
})->with(['admin', 'worker']);

test('a super admin creates another super admin', function () {
    Sanctum::actingAs(User::factory()->superAdmin()->create());

    $this->postJson(route('internal.super-admins.store'), [
        'name' => 'Ops',
        'email' => 'ops@example.com',
        'password' => 'password',
    ])->assertCreated()->assertJsonPath('data.is_super_admin', true);

    $this->assertDatabaseHas('users', ['email' => 'ops@example.com', 'is_super_admin' => true]);
});

test('a regular user is not found through the super admin endpoints', function () {
    Sanctum::actingAs(User::factory()->superAdmin()->create());

    $this->getJson(route('internal.super-admins.show', User::factory()->create()))->assertNotFound();
});

test('a super admin cannot delete their own account', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    Sanctum::actingAs($superAdmin);

    $this->deleteJson(route('internal.super-admins.destroy', $superAdmin))->assertUnprocessable();

    $this->assertModelExists($superAdmin);
});
