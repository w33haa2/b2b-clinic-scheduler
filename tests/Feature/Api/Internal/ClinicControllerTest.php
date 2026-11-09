<?php

use App\Enums\ClinicRoleKey;
use App\Models\Clinic;
use App\Models\ClinicUser;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('creating a clinic creates its built-in roles and first admin', function () {
    Sanctum::actingAs(User::factory()->superAdmin()->create());

    $clinicId = $this->postJson(route('internal.clinics.store'), [
        'name' => 'Northside Clinic',
        'timezone' => 'Asia/Manila',
        'admin' => ['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password'],
    ])->assertCreated()->assertJsonPath('data.timezone', 'Asia/Manila')->json('data.id');

    $clinic = Clinic::query()->findOrFail($clinicId);
    $workerRole = $clinic->clinicRoles()->where('key', ClinicRoleKey::Worker)->firstOrFail();

    expect($clinic->clinicRoles()->pluck('key')->all())->toEqualCanonicalizing([ClinicRoleKey::Admin, ClinicRoleKey::Worker])
        ->and($workerRole->clinicPermissions()->pluck('name')->all())->toEqualCanonicalizing(ClinicRoleKey::Worker->defaultPermissions())
        ->and($clinic->clinicUsers()->sole()->isAdmin())->toBeTrue();
});

test('a super admin manages a clinic users and roles', function () {
    $clinic = Clinic::factory()->create();
    $workerRole = $clinic->clinicRoles()->where('key', ClinicRoleKey::Worker)->firstOrFail();
    Sanctum::actingAs(User::factory()->superAdmin()->create());

    $clinicUserId = $this->postJson(route('internal.clinics.users.store', $clinic), [
        'name' => 'Staff',
        'email' => 'staff@example.com',
        'role_ids' => [$workerRole->id],
    ])->assertCreated()->json('data.id');

    $this->postJson(route('internal.clinics.roles.store', $clinic), ['name' => 'Cashier'])->assertCreated();

    $this->getJson(route('internal.clinics.users.index', $clinic))->assertOk()->assertJsonPath('data.0.id', $clinicUserId);
    $this->getJson(route('internal.clinics.roles.index', $clinic))->assertOk()->assertJsonCount(3, 'data');
});

test('a clinic user is not found through another clinic', function () {
    $clinicUser = ClinicUser::factory()->worker()->create();
    Sanctum::actingAs(User::factory()->superAdmin()->create());

    $this->getJson(route('internal.clinics.users.show', [Clinic::factory()->create(), $clinicUser]))->assertNotFound();
});

test('archiving a clinic keeps its data', function () {
    $clinic = Clinic::factory()->create();
    Sanctum::actingAs(User::factory()->superAdmin()->create());

    $this->deleteJson(route('internal.clinics.destroy', $clinic))->assertNoContent();

    $this->assertSoftDeleted($clinic);
});
