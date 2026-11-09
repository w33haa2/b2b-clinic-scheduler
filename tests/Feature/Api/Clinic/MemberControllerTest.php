<?php

use App\Enums\ClinicRoleKey;
use App\Models\ClinicRole;
use App\Models\ClinicUser;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function workerRoleOf(ClinicUser $member): ClinicRole
{
    return $member->clinic->clinicRoles()->where('key', ClinicRoleKey::Worker)->firstOrFail();
}

test('an admin adds a new member, creating their user account', function () {
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.members.store', $admin->clinic_id), [
        'name' => 'New Worker',
        'email' => 'new.worker@example.com',
        'role_ids' => [workerRoleOf($admin)->id],
    ])->assertCreated()
        ->assertJsonPath('data.user.email', 'new.worker@example.com')
        ->assertJsonPath('data.roles.0.key', 'worker');
});

test('an existing user is attached to another clinic instead of duplicated', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $memberElsewhere = ClinicUser::factory()->worker()->create();
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.members.store', $admin->clinic_id), [
        'name' => 'Ignored',
        'email' => $memberElsewhere->user->email,
        'role_ids' => [workerRoleOf($admin)->id],
    ])->assertCreated()->assertJsonPath('data.user.id', $memberElsewhere->user_id);

    expect(User::query()->where('email', $memberElsewhere->user->email)->count())->toBe(1);
});

test('a super admin cannot be added to a clinic', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $superAdmin = User::factory()->superAdmin()->create();
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.members.store', $admin->clinic_id), [
        'name' => 'Super',
        'email' => $superAdmin->email,
        'role_ids' => [workerRoleOf($admin)->id],
    ])->assertUnprocessable()->assertJsonValidationErrors(['email' => 'Super admins cannot be clinic members.']);
});

test('roles from another clinic cannot be assigned', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $otherClinicRole = ClinicRole::factory()->create();
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.members.store', $admin->clinic_id), [
        'name' => 'New Worker',
        'email' => 'new.worker@example.com',
        'role_ids' => [$otherClinicRole->id],
    ])->assertUnprocessable()->assertJsonValidationErrors('role_ids.0');
});

test('a removed member who is added again gets their membership back', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $worker = ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id]);
    $worker->delete();
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.members.store', $admin->clinic_id), [
        'name' => 'Ignored',
        'email' => $worker->user->email,
        'role_ids' => [workerRoleOf($admin)->id],
    ])->assertOk()->assertJsonPath('data.id', $worker->id);

    expect($worker->fresh()->trashed())->toBeFalse();
});

test('the last clinic admin cannot lose the admin role', function () {
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $this->putJson(route('clinic.members.update', [$admin->clinic_id, $admin]), ['role_ids' => [workerRoleOf($admin)->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['roles' => 'A clinic must keep at least one Clinic Admin.']);
});

test('an admin can step down when another admin remains', function () {
    $admin = ClinicUser::factory()->admin()->create();
    ClinicUser::factory()->admin()->create(['clinic_id' => $admin->clinic_id]);
    Sanctum::actingAs($admin->user);

    $this->putJson(route('clinic.members.update', [$admin->clinic_id, $admin]), ['role_ids' => [workerRoleOf($admin)->id]])
        ->assertOk()
        ->assertJsonPath('data.roles.0.key', 'worker');
});

test('removing a member archives the membership and revokes access', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $worker = ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id]);
    Sanctum::actingAs($admin->user);

    $this->deleteJson(route('clinic.members.destroy', [$admin->clinic_id, $worker]))->assertNoContent();

    $this->assertSoftDeleted($worker);

    Sanctum::actingAs($worker->user);
    $this->getJson(route('clinic.show', $admin->clinic_id))->assertNotFound();
});
