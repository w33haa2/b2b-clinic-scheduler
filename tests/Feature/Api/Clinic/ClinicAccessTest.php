<?php

use App\Enums\ClinicRoleKey;
use App\Models\Clinic;
use App\Models\ClinicPermission;
use App\Models\ClinicRole;
use App\Models\ClinicUser;
use App\Models\Patient;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('a user who is not a member of the clinic gets 404', function () {
    $clinic = Clinic::factory()->create();
    Sanctum::actingAs(ClinicUser::factory()->admin()->create()->user);

    $this->getJson(route('clinic.patients.index', $clinic))->assertNotFound();
});

test('a removed member gets 404', function () {
    $member = ClinicUser::factory()->admin()->create();
    ClinicUser::factory()->admin()->create(['clinic_id' => $member->clinic_id]);
    $member->delete();
    Sanctum::actingAs($member->user);

    $this->getJson(route('clinic.show', $member->clinic_id))->assertNotFound();
});

test('members of an archived clinic get 404', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $admin->clinic->delete();
    Sanctum::actingAs($admin->user);

    $this->getJson(route('clinic.show', $admin->clinic_id))->assertNotFound();
});

test('a member without the route permission gets 403', function () {
    $worker = ClinicUser::factory()->worker()->create();
    Sanctum::actingAs($worker->user);

    $this->getJson(route('clinic.invoices.index', $worker->clinic_id))->assertForbidden();
});

test('a custom role grants exactly the permissions bound to it', function () {
    $role = ClinicRole::factory()->withPermissions(['clinic.invoices.index'])->create();
    $member = ClinicUser::factory()->withRole($role)->create();
    Sanctum::actingAs($member->user);

    $this->getJson(route('clinic.invoices.index', $role->clinic_id))->assertOk();
    $this->getJson(route('clinic.patients.index', $role->clinic_id))->assertForbidden();
});

test('a clinic admin passes every permission check', function () {
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $this->getJson(route('clinic.transactions.index', $admin->clinic_id))->assertOk();
});

test('admin-only routes refuse members whose roles hold every grantable permission', function () {
    $role = ClinicRole::factory()->create();
    $role->clinicPermissions()->sync(ClinicPermission::query()->pluck('id'));
    Sanctum::actingAs(ClinicUser::factory()->withRole($role)->create()->user);

    $this->postJson(route('clinic.roles.store', $role->clinic_id), ['name' => 'Escalated'])->assertForbidden();
});

test('a super admin cannot use the clinic api', function () {
    $clinic = Clinic::factory()->create();
    Sanctum::actingAs(User::factory()->superAdmin()->create());

    $this->getJson(route('clinic.show', $clinic))->assertNotFound();
});

test('a record from another clinic is not found through this clinic', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $otherClinicsPatient = Patient::factory()->create();
    Sanctum::actingAs($admin->user);

    $this->getJson(route('clinic.patients.show', [$admin->clinic_id, $otherClinicsPatient]))->assertNotFound();
});

test('the clinic endpoint returns the member roles and effective permissions', function () {
    $worker = ClinicUser::factory()->worker()->create();
    Sanctum::actingAs($worker->user);

    $this->getJson(route('clinic.show', $worker->clinic_id))
        ->assertOk()
        ->assertJsonPath('meta.is_admin', false)
        ->assertJsonPath('meta.roles.0.key', 'worker')
        ->assertJsonPath('meta.permissions', collect(ClinicRoleKey::Worker->defaultPermissions())->sort()->values()->all());
});

test('my clinics lists only the clinics the user belongs to', function () {
    $member = ClinicUser::factory()->worker()->create();
    Clinic::factory()->create();
    Sanctum::actingAs($member->user);

    $this->getJson(route('clinics.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $member->clinic_id);
});
