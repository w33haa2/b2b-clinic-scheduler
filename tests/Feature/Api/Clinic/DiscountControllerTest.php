<?php

use App\Enums\DiscountTarget;
use App\Enums\DiscountType;
use App\Models\ClinicRole;
use App\Models\ClinicUser;
use App\Models\Discount;
use Laravel\Sanctum\Sanctum;

test('the list shows clinic-wide discounts plus the member own ones, and nobody else personal ones', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $colleague = ClinicUser::factory()->worker()->create(['clinic_id' => $worker->clinic_id]);
    $clinicWide = Discount::factory()->for($worker->clinic)->create(['name' => 'Senior citizen']);
    $mine = Discount::factory()->personal($worker)->create(['name' => 'My referral deal']);
    Discount::factory()->personal($colleague)->create(['name' => 'Their secret deal']);
    Sanctum::actingAs($worker->user);

    $this->getJson(route('clinic.discounts.index', $worker->clinic_id))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $clinicWide->id)
        ->assertJsonPath('data.0.scope', 'CLINIC_WIDE')
        ->assertJsonPath('data.1.id', $mine->id)
        ->assertJsonPath('data.1.scope', 'PERSONAL');

    $this->getJson(route('clinic.discounts.index', [$worker->clinic_id, 'scope' => 'PERSONAL']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id);
});

test('a colleague personal discount reads as missing, even to an admin', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $worker = ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id]);
    $personal = Discount::factory()->personal($worker)->create();
    Sanctum::actingAs($admin->user);

    $this->getJson(route('clinic.discounts.index', $admin->clinic_id))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(route('clinic.discounts.show', [$admin->clinic_id, $personal]))->assertNotFound();
    $this->deleteJson(route('clinic.discounts.destroy', [$admin->clinic_id, $personal]))->assertNotFound();
});

test('an admin creates a clinic-wide discount and says whose cut pays for it', function () {
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.discounts.store', $admin->clinic_id), [
        'scope' => 'CLINIC_WIDE',
        'name' => 'Staff family',
        'type' => 'PERCENTAGE',
        'target' => 'WORKER',
        'value' => '15.00',
    ])->assertCreated()
        ->assertJsonPath('data.scope', 'CLINIC_WIDE')
        ->assertJsonPath('data.target', 'WORKER')
        ->assertJsonPath('data.owner_id', null);

    $this->assertDatabaseHas('discounts', [
        'clinic_id' => $admin->clinic_id,
        'clinic_user_id' => null,
        'name' => 'Staff family',
        'discount_type_id' => DiscountType::Percentage->value,
        'discount_target_id' => DiscountTarget::Worker->value,
    ]);
});

test('a worker can only create personal discounts', function () {
    $worker = ClinicUser::factory()->worker()->create();
    Sanctum::actingAs($worker->user);

    $this->postJson(route('clinic.discounts.store', $worker->clinic_id), [
        'scope' => 'CLINIC_WIDE',
        'name' => 'Everyone gets this',
        'type' => 'FIXED',
        'target' => 'CLINIC',
        'value' => '50.00',
    ])->assertUnprocessable()->assertJsonValidationErrors(['scope' => 'Only a Clinic Admin can create a clinic-wide discount.']);

    $this->postJson(route('clinic.discounts.store', $worker->clinic_id), [
        'scope' => 'PERSONAL',
        'name' => 'My own deal',
        'type' => 'FIXED',
        'target' => 'CLINIC',
        'value' => '50.00',
    ])->assertCreated()->assertJsonPath('data.owner_id', $worker->id);
});

test('a worker cannot change or archive a clinic-wide discount', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $clinicWide = Discount::factory()->for($worker->clinic)->create();
    Sanctum::actingAs($worker->user);

    $this->patchJson(route('clinic.discounts.update', [$worker->clinic_id, $clinicWide]), ['name' => 'Mine now'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['scope' => 'Only a Clinic Admin can change a clinic-wide discount.']);

    $this->deleteJson(route('clinic.discounts.destroy', [$worker->clinic_id, $clinicWide]))->assertUnprocessable();
});

test('a percentage discount cannot be more than a hundred', function () {
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.discounts.store', $admin->clinic_id), [
        'scope' => 'CLINIC_WIDE',
        'name' => 'Too much',
        'type' => 'PERCENTAGE',
        'target' => 'CLINIC',
        'value' => '120.00',
    ])->assertUnprocessable()->assertJsonValidationErrors(['value' => 'A percentage discount cannot be more than 100.']);
});

test('archiving a discount keeps it off the list', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $discount = Discount::factory()->for($admin->clinic)->create();
    Sanctum::actingAs($admin->user);

    $this->deleteJson(route('clinic.discounts.destroy', [$admin->clinic_id, $discount]))->assertNoContent();

    $this->assertSoftDeleted('discounts', ['id' => $discount->id]);
    $this->getJson(route('clinic.discounts.index', $admin->clinic_id))->assertOk()->assertJsonCount(0, 'data');
});

test('discounts of another clinic are out of reach', function () {
    $foreign = Discount::factory()->create();
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $this->getJson(route('clinic.discounts.show', [$admin->clinic_id, $foreign]))->assertNotFound();
    $this->patchJson(route('clinic.discounts.update', [$admin->clinic_id, $foreign]), ['name' => 'x'])->assertNotFound();
});

test('a role without the discount permissions cannot list or create', function () {
    $role = ClinicRole::factory()->withPermissions(['clinic.sessions.index'])->create();
    $member = ClinicUser::factory()->withRole($role)->create();
    Sanctum::actingAs($member->user);

    $this->getJson(route('clinic.discounts.index', $role->clinic_id))->assertForbidden();
    $this->postJson(route('clinic.discounts.store', $role->clinic_id), [])->assertForbidden();
});
